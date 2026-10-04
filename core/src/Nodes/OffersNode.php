<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Nodes;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\StructuredOutput\Deserializer\DeserializerException;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronBook\TripPlanner\Agents\OfferScoutAgent;
use NeuronBook\TripPlanner\Events\OffersChosen;
use NeuronBook\TripPlanner\Events\WindowAgreed;
use NeuronBook\TripPlanner\Output\TripChoice;
use NeuronBook\TripPlanner\Requests\DecisionRequest;
use NeuronBook\TripPlanner\Tools\SearchFlightsTool;
use NeuronBook\TripPlanner\Tools\SearchHotelsTool;
use NeuronBook\TripPlanner\TripServices;
use NeuronBook\TripPlanner\TripState;

/**
 * Step 3 - which flight, which hotel. Human checkpoint #2.
 *
 * Same loop shape as WindowNode. What is new is the trust boundary: the
 * scout returns two IDs, and this node looks both up in the inventory before
 * showing anything. An ID the search never returned - a hallucination, or a
 * prompt injection hidden in an offer name - is rejected here, and every
 * price the traveller sees comes from the inventory, not from the model.
 */
class OffersNode extends Node
{
    public const MAX_ROUNDS = 3;

    public function __construct(private readonly TripServices $services)
    {
    }

    public function __invoke(WindowAgreed $event, TripState $state): WindowAgreed|OffersChosen|StopEvent
    {
        $round = \count($state->feedback('offers'));

        /** @var array{flight: array<string, mixed>, hotel: array<string, mixed>, total: float, over_budget: bool, reasoning: string}|array{invalid: string} $proposal */
        $proposal = $this->memoize("offers-{$round}", fn (): array => $this->propose($state));

        if (isset($proposal['invalid'])) {
            return $this->revise($state, "(automatic check) {$proposal['invalid']}");
        }

        $payload = $this->interrupt(new DecisionRequest(
            stage: 'offers',
            message: \sprintf(
                '%s + %s: %.2f EUR%s',
                $proposal['flight']['airline'],
                $proposal['hotel']['name'],
                $proposal['total'],
                $proposal['over_budget'] ? ' (over budget)' : '',
            ),
            details: $proposal,
        ));

        if (($payload['decision'] ?? null) === 'approve') {
            $state->set('selection', [
                'flight_id' => (string) $proposal['flight']['id'],
                'hotel_id' => (string) $proposal['hotel']['id'],
                'reasoning' => $proposal['reasoning'],
            ]);

            return new OffersChosen();
        }

        return $this->revise($state, (string) ($payload['feedback'] ?? 'Show me a different combination.'));
    }

    private function revise(TripState $state, string $feedback): WindowAgreed|StopEvent
    {
        $state->addFeedback('offers', $feedback);

        if (\count($state->feedback('offers')) >= self::MAX_ROUNDS) {
            $state->finish('no_agreement', 'No flight and hotel agreed after ' . self::MAX_ROUNDS . ' proposals.');

            return new StopEvent();
        }

        return new WindowAgreed();
    }

    /**
     * @return array{flight: array<string, mixed>, hotel: array<string, mixed>, total: float, over_budget: bool, reasoning: string}|array{invalid: string}
     */
    private function propose(TripState $state): array
    {
        $request = $state->request();
        $window = $state->window();
        $origin = $state->origin();
        $destination = $state->destination();
        $inventory = $this->services->inventory;

        $agent = $this->services->wire(OfferScoutAgent::make(workflowId: "{$state->getWorkflowId()}:scout"));
        $agent->addTool([
            new SearchFlightsTool($inventory, $origin, $destination, $window['start'], $window['end'], $request['travellers']),
            new SearchHotelsTool($inventory, $destination, $window['start'], $window['end'], $request['travellers']),
        ]);

        $prompt = \implode("\n", [
            "Trip: {$window['name']}, {$window['start']} to {$window['end']}, {$request['travellers']} travellers from {$origin->label()}.",
            'Budget for flights and hotel together: ' . ($request['budget'] > 0 ? "{$request['budget']} EUR" : 'not stated') . '.',
            'What they care about: ' . ($request['preferences'] !== '' ? $request['preferences'] : 'nothing specific') . '.',
            ...($state->feedback('offers') === [] ? [] : [
                'The traveller rejected earlier choices. Their feedback, oldest first:',
                ...\array_map(static fn (string $f): string => "- {$f}", $state->feedback('offers')),
            ]),
        ]);

        try {
            $choice = $agent->structured(new UserMessage($prompt), TripChoice::class, maxRetries: 2);
        } catch (AgentException|DeserializerException $e) {
            return ['invalid' => 'The last choice was incomplete: ' . \trim(\str_replace("\n", ' ', $e->getMessage()))];
        }
        \assert($choice instanceof TripChoice);

        $flight = $inventory->quoteFlight($choice->flight_offer_id);
        $hotel = $inventory->quoteHotel($choice->hotel_offer_id);

        if ($flight === null || $hotel === null) {
            return ['invalid' => 'Use only offer ids returned by search_flights and search_hotels; '
                . "'{$choice->flight_offer_id}' / '{$choice->hotel_offer_id}' were not among them."];
        }

        if ($flight->departDate !== $window['start'] || $hotel->checkIn !== $window['start'] || $hotel->checkOut !== $window['end']) {
            return ['invalid' => 'The chosen offers do not match the agreed dates.'];
        }

        $total = \round($flight->total() + $hotel->total(), 2);

        return [
            'flight' => $flight->summary(),
            'hotel' => $hotel->summary(),
            'total' => $total,
            'over_budget' => $request['budget'] > 0 && $total > $request['budget'],
            'reasoning' => $choice->reasoning,
        ];
    }
}
