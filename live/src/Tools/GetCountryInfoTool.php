<?php

declare(strict_types=1);

namespace NeuronBook\TripPlannerLive\Tools;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;
use NeuronBook\TripPlannerLive\Api\ApiUnavailable;
use NeuronBook\TripPlannerLive\Api\RestCountries;

/**
 * Practical facts about a country: currency, languages, capital, driving side.
 */
class GetCountryInfoTool extends LiveTool
{
    protected string $name = 'get_country_info';

    protected ?string $description = 'Returns practical facts about a country from a live country database: capital, '
        . 'currencies (ISO code and symbol), languages, international calling code, driving side, time zones and '
        . 'population. Use it for the local currency before converting a budget, and for what a traveller should expect '
        . 'on arrival. It says nothing about visas, prices or safety.';

    public function __construct(private readonly RestCountries $countries)
    {
    }

    /**
     * @return ToolProperty[]
     */
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'country_code',
                type: PropertyType::STRING,
                description: 'ISO 3166-1 alpha-2 code of the country, as get_monthly_climate returned it. Examples: "PT", "JP", "MX".',
                required: true,
            ),
        ];
    }

    public function __invoke(string $country_code): string|ToolOutput
    {
        try {
            $country = $this->countries->byCode($country_code);
        } catch (ApiUnavailable $e) {
            return self::unavailable('country database', $e);
        }

        return $country === null
            ? ToolOutput::error("No country has the code \"{$country_code}\". Use the two-letter ISO code returned by get_monthly_climate.")
            : self::json($country);
    }
}
