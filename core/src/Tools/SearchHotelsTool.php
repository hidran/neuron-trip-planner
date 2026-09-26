<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Tools;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronBook\TripPlanner\Geo\Place;
use NeuronBook\TripPlanner\Travel\HotelOffer;
use NeuronBook\TripPlanner\Travel\Inventory;

/**
 * Hotel search, scoped the same way as SearchFlightsTool.
 */
class SearchHotelsTool extends Tool
{
    protected string $name = 'search_hotels';

    protected ?string $description = 'Searches hotels in the agreed city for the agreed nights and party. Returns offers with '
        . 'an id, name, stars, guest rating (0-10), nightly rate and total in EUR, and whether cancellation is free. '
        . 'Call it before choosing a hotel; use min_stars when the traveller asks for a better or cheaper hotel.';

    public function __construct(
        private readonly Inventory $inventory,
        private readonly Place $destination,
        private readonly string $checkIn,
        private readonly string $checkOut,
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
                name: 'min_stars',
                type: PropertyType::INTEGER,
                description: 'Minimum hotel category, 1 to 5. Omit for no limit.',
                required: false,
            ),
        ];
    }

    public function __invoke(?int $min_stars = null): string
    {
        $offers = $this->inventory->searchHotels($this->destination, $this->checkIn, $this->checkOut, $this->travellers)
            |> (static fn (array $all): array => $min_stars === null
                ? $all
                : \array_values(\array_filter($all, static fn (HotelOffer $o): bool => $o->stars >= $min_stars)));

        if ($offers === []) {
            return 'No hotels match. Try a lower min_stars.';
        }

        return \json_encode(\array_map(static fn (HotelOffer $o): array => $o->summary(), $offers), \JSON_THROW_ON_ERROR);
    }
}
