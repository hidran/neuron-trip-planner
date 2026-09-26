<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Tools;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;
use NeuronBook\TripPlanner\Climate\ClimateSource;
use NeuronBook\TripPlanner\Geo\PlaceDirectory;

/**
 * Twelve months of observed weather for any city in the world.
 *
 * The model names a city; the tool geocodes it and returns a place_id with
 * the data. That ID is what the advisor must propose - the node only accepts
 * IDs a search really returned, so the coordinates behind every later step
 * came from the geocoder, never from text the model wrote.
 */
class MonthlyClimateTool extends Tool
{
    protected string $name = 'get_monthly_climate';

    protected ?string $description = 'Looks up a city anywhere in the world and returns its place_id and last year\'s '
        . 'observed weather, month by month: average daily maximum temperature (C), total rain (mm), rainy days and '
        . 'humidity. Call it for every city you are considering before recommending one. Never describe the weather '
        . 'of a place without calling this first.';

    public function __construct(
        private readonly PlaceDirectory $places,
        private readonly ClimateSource $climate,
    ) {
    }

    /**
     * @return ToolProperty[]
     */
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'city',
                type: PropertyType::STRING,
                description: 'City name in English. Examples: "Kyoto", "Cape Town", "Cancún".',
                required: true,
            ),
            new ToolProperty(
                name: 'country_code',
                type: PropertyType::STRING,
                description: 'ISO 3166-1 alpha-2 country code, to disambiguate. Examples: "JP", "ZA", "MX". Omit if unsure.',
                required: false,
            ),
        ];
    }

    public function __invoke(string $city, ?string $country_code = null): string|ToolOutput
    {
        try {
            // PHP 8.5: array_first() - the most populous match.
            $place = \array_first($this->places->search($city, $country_code !== '' ? $country_code : null));

            if ($place === null) {
                return ToolOutput::error("No city called \"{$city}\" was found. Check the spelling, or add or drop country_code.");
            }

            return \json_encode([
                ...$place->summary(),
                'months' => $this->climate->monthly($place),
            ], \JSON_THROW_ON_ERROR);
        } catch (HttpException) {
            return ToolOutput::error('The weather service is unreachable. Try once more, then base the advice on general knowledge and say so.');
        }
    }
}
