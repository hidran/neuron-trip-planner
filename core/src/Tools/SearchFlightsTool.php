<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Tools;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronBook\TripPlanner\Geo\Place;
use NeuronBook\TripPlanner\Travel\FlightOffer;
use NeuronBook\TripPlanner\Travel\Inventory;

/**
 * Flight search, scoped to the trip the traveller already approved.
 *
 * Both places, the dates and the party size are constructor dependencies,
 * fixed when the node builds the tool. The model can filter and choose; it
 * cannot quietly search other dates or cities than the ones the human agreed.
 */
class SearchFlightsTool extends Tool
{
    protected string $name = 'search_flights';

    protected ?string $description = 'Searches round-trip flights for the agreed cities, dates and party. Returns offers with '
        . 'an id, airline, stops (and via which hub), hours each way and prices in EUR. Call it before choosing a flight; '
        . 'use max_stops when the traveller wants direct or shorter flights.';

    public function __construct(
        private readonly Inventory $inventory,
        private readonly Place $origin,
        private readonly Place $destination,
        private readonly string $depart,
        private readonly string $return,
        private readonly int $travellers,
    ) {
    }

    /**
     * @return ToolProperty[]
     */
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'max_stops',
                type: PropertyType::INTEGER,
                description: 'Maximum number of stops each way. 0 for direct flights only. Omit for no limit.',
                required: false,
            ),
        ];
    }

    public function __invoke(?int $max_stops = null): string
    {
        $offers = $this->inventory->searchFlights($this->origin, $this->destination, $this->depart, $this->return, $this->travellers)
            |> (static fn (array $all): array => $max_stops === null
                ? $all
                : \array_values(\array_filter($all, static fn (FlightOffer $o): bool => $o->stops <= $max_stops)));

        if ($offers === []) {
            return $max_stops === null
                ? 'No flights: the two cities are too close to fly between.'
                : 'No flights match. Try without max_stops.';
        }

        return \json_encode(\array_map(static fn (FlightOffer $o): array => $o->summary(), $offers), \JSON_THROW_ON_ERROR);
    }
}
