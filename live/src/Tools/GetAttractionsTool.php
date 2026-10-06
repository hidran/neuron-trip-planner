<?php

declare(strict_types=1);

namespace NeuronBook\TripPlannerLive\Tools;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;
use NeuronBook\TripPlanner\Geo\PlaceDirectory;
use NeuronBook\TripPlannerLive\Api\ApiUnavailable;
use NeuronBook\TripPlannerLive\Api\Overpass;

/**
 * Real things to see near the destination, from OpenStreetMap.
 */
class GetAttractionsTool extends LiveTool
{
    protected string $name = 'get_attractions';

    protected ?string $description = 'Lists named attractions, museums, viewpoints and galleries within a few kilometres '
        . 'of a place, from OpenStreetMap, the best-known first. Use it to check that a destination offers what the '
        . 'traveller said they love, and to name two or three concrete things. It does not give opening hours or prices.';

    public function __construct(
        private readonly PlaceDirectory $places,
        private readonly Overpass $attractions,
    ) {
    }

    /**
     * @return ToolProperty[]
     */
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'place_id', type: PropertyType::INTEGER, description: 'The place_id returned by get_monthly_climate.', required: true),
        ];
    }

    public function __invoke(int $place_id): string|ToolOutput
    {
        $place = self::place($this->places, $place_id);

        if ($place instanceof ToolOutput) {
            return $place;
        }

        try {
            $found = $this->attractions->attractions($place->latitude, $place->longitude);
        } catch (ApiUnavailable $e) {
            return self::unavailable('map', $e);
        }

        return self::json(['place' => $place->label(), 'attractions' => $found]);
    }
}
