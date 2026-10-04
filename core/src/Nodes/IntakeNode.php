<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Nodes;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\StructuredOutput\Deserializer\DeserializerException;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronBook\TripPlanner\Agents\IntakeAgent;
use NeuronBook\TripPlanner\DatePreferences;
use NeuronBook\TripPlanner\Events\RequestUnderstood;
use NeuronBook\TripPlanner\Output\TripRequest;
use NeuronBook\TripPlanner\TripServices;
use NeuronBook\TripPlanner\TripState;

/**
 * Step 1 - read the traveller's request.
 *
 * Wrapped in memoize() like every model call in this workflow: a durable step
 * that completed is never re-run, but memoize() also covers the case where
 * this node is still running when the process dies.
 */
class IntakeNode extends Node
{
    public function __construct(private readonly TripServices $services)
    {
    }

    public function __invoke(StartEvent $event, TripState $state): RequestUnderstood|StopEvent
    {
        /** @var array{origin_city: string, origin_country_code: string, travellers: int, nights: int, budget: int, preferences: string}|array{invalid: string} $request */
        $request = $this->memoize('intake', function () use ($state): array {
            try {
                $result = $this->services->wire(IntakeAgent::make())->structured(
                    new UserMessage($state->ask()),
                    TripRequest::class,
                    maxRetries: 2,
                );
            } catch (AgentException|DeserializerException $e) {
                return ['invalid' => \trim(\str_replace("\n", ' ', $e->getMessage()))];
            }
            \assert($result instanceof TripRequest);

            return $result->toArray();
        });

        if (isset($request['invalid'])) {
            $state->finish('not_understood', "Could not read the request ({$request['invalid']}). Say which city you leave from, how many travel and for how long.");

            return new StopEvent();
        }

        $state->set('request', $request);

        // The city is a name the model read; the place is what the geocoder
        // says it is. Memoized: it is a network call, and a resumed run should
        // not ask again.
        $origin = $this->memoize('intake-origin', fn (): ?array => \array_first(
            $this->services->places->search($request['origin_city'], $request['origin_country_code'] !== '' ? $request['origin_country_code'] : null),
        )?->toArray());

        if ($origin === null) {
            $state->finish('not_understood', "Could not find a city called \"{$request['origin_city']}\". Say which city you leave from.");

            return new StopEvent();
        }

        $state->set('origin', $origin);

        // "In the second half of June" is not a preference to weigh against
        // the weather - it is a constraint. Extract it as dates so WindowNode
        // can enforce it in code.
        $state->applyDatePreference($this->memoize(
            'intake-dates',
            fn (): array => DatePreferences::extract($this->services, $state->ask(), $state->today()),
        ));

        return new RequestUnderstood();
    }
}
