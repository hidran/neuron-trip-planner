<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Nodes;

use DateTimeImmutable;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\StructuredOutput\Deserializer\DeserializerException;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronBook\TripPlanner\Agents\SeasonAdvisorAgent;
use NeuronBook\TripPlanner\DatePreferences;
use NeuronBook\TripPlanner\Events\RequestUnderstood;
use NeuronBook\TripPlanner\Events\WindowAgreed;
use NeuronBook\TripPlanner\Output\TravelWindow;
use NeuronBook\TripPlanner\Requests\DecisionRequest;
use NeuronBook\TripPlanner\Tools\MonthlyClimateTool;
use NeuronBook\TripPlanner\TripServices;
use NeuronBook\TripPlanner\TripState;

/**
 * Step 2 - where and when. Human checkpoint #1.
 *
 * The loop is the workflow graph, not a PHP loop: a "revise" answer records
 * the feedback and returns RequestUnderstood, the event this node consumes,
 * so the next step is this node again with one more piece of feedback.
 *
 * Three things keep that loop honest:
 *
 * - the memo name includes the round, so each round asks the model once and
 *   a resume inside a round reuses that round's proposal - the traveller
 *   approves the proposal they actually saw;
 * - the round is derived from the feedback list, which only grows AFTER the
 *   interrupt has returned, so re-executing the node cannot miscount;
 * - it is bounded. A traveller and a model that never agree is a bill.
 */
class WindowNode extends Node
{
    public const MAX_ROUNDS = 3;

    public function __construct(private readonly TripServices $services)
    {
    }

    public function __invoke(RequestUnderstood $event, TripState $state): RequestUnderstood|WindowAgreed|StopEvent
    {
        $round = \count($state->feedback('window'));

        /** @var array{place: array<string, mixed>, name: string, start: string, end: string, weather: string, reasoning: string}|array{invalid: string}|array{unbookable: string} $proposal */
        $proposal = $this->memoize("window-{$round}", fn (): array => $this->propose($state));

        if (isset($proposal['unbookable'])) {
            $state->finish('dates_not_bookable', $proposal['unbookable']);

            return new StopEvent();
        }

        if (isset($proposal['invalid'])) {
            // The model broke a rule the code can check. That is not worth a
            // human's time: feed it back and let the next round fix it.
            return $this->revise($state, "(automatic check) {$proposal['invalid']}");
        }

        $payload = $this->interrupt(new DecisionRequest(
            stage: 'window',
            message: "{$proposal['name']}, {$proposal['start']} to {$proposal['end']}",
            details: $proposal,
        ));

        if (($payload['decision'] ?? null) === 'approve') {
            $state->set('window', $proposal);

            return new WindowAgreed();
        }

        $feedback = (string) ($payload['feedback'] ?? 'Propose something different.');

        // "Let's go on 5 April instead" must bind the next round, not just be
        // read by it: extract the timing and let propose() enforce it.
        $state->applyDatePreference($this->memoize(
            "feedback-dates-{$round}",
            fn (): array => DatePreferences::extract($this->services, $feedback, $state->today(), "{$state->getWorkflowId()}:dates"),
        ));

        return $this->revise($state, $feedback);
    }

    private function revise(TripState $state, string $feedback): RequestUnderstood|StopEvent
    {
        $state->addFeedback('window', $feedback);

        if (\count($state->feedback('window')) >= self::MAX_ROUNDS) {
            $state->finish('no_agreement', 'No travel window agreed after ' . self::MAX_ROUNDS . ' proposals.');

            return new StopEvent();
        }

        return new RequestUnderstood();
    }

    /**
     * @return array{place: array<string, mixed>, name: string, start: string, end: string, weather: string, reasoning: string}|array{invalid: string}|array{unbookable: string}
     */
    private function propose(TripState $state): array
    {
        $request = $state->request();
        $today = new DateTimeImmutable($state->today());
        $bookableFrom = $today->modify('+14 days');
        $bookableTo = $today->modify('+12 months');

        // The traveller's own range, intersected with what can be booked.
        $wanted = $state->dates();
        $chosen = $wanted['earliest'] !== '' || $wanted['latest'] !== '';
        $earliest = $wanted['earliest'] !== '' ? \max($bookableFrom, new DateTimeImmutable($wanted['earliest'])) : $bookableFrom;
        $latest = $wanted['latest'] !== '' ? \min($bookableTo, new DateTimeImmutable($wanted['latest'])) : $bookableTo;

        if ($earliest > $latest) {
            return ['unbookable' => \sprintf(
                'You asked to start between %s and %s, but trips can only be booked to start between %s and %s.',
                $wanted['earliest'] !== '' ? $wanted['earliest'] : 'any time',
                $wanted['latest'] !== '' ? $wanted['latest'] : 'any time',
                $bookableFrom->format('Y-m-d'),
                $bookableTo->format('Y-m-d'),
            )];
        }

        $range = "between {$earliest->format('Y-m-d')} and {$latest->format('Y-m-d')}";

        $prompt = \implode("\n", [
            "Today is {$today->format('Y-m-d')}.",
            $chosen
                ? "HARD CONSTRAINT, set by the traveller: the trip starts {$range}. Choose the best destination and start date "
                    . 'INSIDE this range. Never propose a date outside it, even if the weather elsewhere in the year is better; '
                    . 'if the weather in this range is poor, choose the best option anyway and say so plainly in weather_summary.'
                : "The trip must start {$range}. Choose the period with the best weather for this traveller.",
            "Travellers: {$request['travellers']}, leaving from {$state->origin()->label()}, for {$request['nights']} nights.",
            'Budget: ' . ($request['budget'] > 0 ? "{$request['budget']} EUR in total" : 'not stated') . '.',
            'What they care about: ' . ($request['preferences'] !== '' ? $request['preferences'] : 'nothing specific') . '.',
            "Their words: \"{$state->ask()}\"",
            ...$this->feedbackLines($state->feedback('window')),
        ]);

        $agent = $this->services->wire(SeasonAdvisorAgent::make(workflowId: "{$state->getWorkflowId()}:advisor"));
        $agent->addTool(new MonthlyClimateTool($this->services->places, $this->services->climate));

        try {
            $window = $agent->structured(new UserMessage($prompt), TravelWindow::class, maxRetries: 2);
        } catch (AgentException|DeserializerException $e) {
            // Retries exhausted without a valid structure - common with small
            // local models. Treat it like any rule the code can check: one
            // more bounded round, with the violations as feedback.
            return ['invalid' => 'The last proposal was incomplete: ' . \trim(\str_replace("\n", ' ', $e->getMessage()))];
        }
        \assert($window instanceof TravelWindow);

        // The trust boundary: only a place a tool really returned.
        $place = $this->services->places->find($window->destination_place_id);

        if ($place === null) {
            return ['invalid' => "Use a place_id returned by get_monthly_climate; {$window->destination_place_id} was not among them."];
        }

        if ($place->distanceTo($state->origin()) < 150) {
            return ['invalid' => "{$place->label()} is where the traveller starts from. Propose somewhere to travel to."];
        }

        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $window->start_date);

        if ($start === false || $start < $earliest || $start > $latest) {
            return ['invalid' => "The start date {$window->start_date} is outside the allowed range: the trip must start {$range}"
                . ($chosen ? ', as the traveller asked.' : '.')];
        }

        return [
            'place' => $place->toArray(),
            'name' => $place->label(),
            'start' => $start->format('Y-m-d'),
            // Arithmetic is the application's job, not the model's.
            'end' => $start->modify("+{$request['nights']} days")->format('Y-m-d'),
            'weather' => $window->weather_summary,
            'reasoning' => $window->reasoning,
        ];
    }

    /**
     * @param list<string> $feedback
     * @return list<string>
     */
    private function feedbackLines(array $feedback): array
    {
        if ($feedback === []) {
            return [];
        }

        return ['The traveller rejected earlier proposals. Their feedback, oldest first:', ...\array_map(static fn (string $f): string => "- {$f}", $feedback)];
    }
}
