<?php

declare(strict_types=1);

namespace NeuronBook\TripPlannerLive\Tools;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;
use NeuronBook\TripPlannerLive\Api\ApiUnavailable;
use NeuronBook\TripPlannerLive\Api\TravelAdvisory;

/**
 * An aggregate travel-risk score for a country.
 */
class GetTravelAdvisoryTool extends LiveTool
{
    protected string $name = 'get_travel_advisory';

    protected ?string $description = 'Returns an aggregate travel-risk score for a country (0 = very safe, 5 = extreme '
        . 'risk) with a plain-language level, the date it was updated and a link to the source. It summarises '
        . 'government advisories: report it as a signal with its date, never as a guarantee, and tell the traveller to '
        . 'check their own government\'s current advice before booking.';

    public function __construct(private readonly TravelAdvisory $advisories)
    {
    }

    /**
     * @return ToolProperty[]
     */
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'country_code', type: PropertyType::STRING, description: 'ISO 3166-1 alpha-2 code. Example: "MX".', required: true),
        ];
    }

    public function __invoke(string $country_code): string|ToolOutput
    {
        try {
            $advisory = $this->advisories->forCountry($country_code);
        } catch (ApiUnavailable $e) {
            return self::unavailable('travel-advisory', $e);
        }

        return $advisory === null
            ? ToolOutput::error("There is no advisory entry for \"{$country_code}\". Say that you could not check the advisory level.")
            : self::json($advisory);
    }
}
