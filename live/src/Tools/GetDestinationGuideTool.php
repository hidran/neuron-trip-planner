<?php

declare(strict_types=1);

namespace NeuronBook\TripPlannerLive\Tools;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;
use NeuronBook\TripPlanner\Geo\PlaceDirectory;
use NeuronBook\TripPlannerLive\Api\ApiUnavailable;
use NeuronBook\TripPlannerLive\Api\WikiSummary;

/**
 * The travel-guide introduction for a destination.
 */
class GetDestinationGuideTool extends LiveTool
{
    protected string $name = 'get_destination_guide';

    protected ?string $description = 'Returns the introduction of the destination\'s page on Wikivoyage, the free travel '
        . 'guide (falling back to Wikipedia when there is no guide page yet): what the place is known for and how to '
        . 'think about visiting it. Use it for one or two sentences of local colour in your reasoning.';

    public function __construct(
        private readonly PlaceDirectory $places,
        private readonly WikiSummary $guide,
        private readonly WikiSummary $encyclopedia,
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
            // "Kyoto" first; a name shared by several places needs the region to land on the right page.
            $page = $this->guide->summary($place->name)
                ?? $this->guide->summary("{$place->name}, {$place->country}")
                ?? $this->encyclopedia->summary($place->name);
        } catch (ApiUnavailable $e) {
            return self::unavailable('travel-guide', $e);
        }

        return $page === null
            ? ToolOutput::error("No guide page was found for {$place->label()}. Say so, and do not describe the place from memory.")
            : self::json($page);
    }
}
