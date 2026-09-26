<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Output;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\GreaterThanEqual;
use NeuronAI\StructuredOutput\Validation\Rules\IsNotNull;
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;
use NeuronAI\StructuredOutput\Validation\Rules\Regex;

/**
 * What the traveller asked for, extracted from free text by the intake agent.
 *
 * The origin is a city name, not coordinates or an airport code: the intake
 * node geocodes it, so a typo becomes a clear question, not a flight from the
 * wrong continent.
 *
 * Every required property carries a rule as well as `required: true`: the
 * schema flag is only a hint to the model, the rule is what turns an omitted
 * key into a retry instead of an uninitialised property (Section 6.4).
 */
class TripRequest
{
    #[SchemaProperty(
        description: 'The city the traveller departs from, in English. Examples: "Milan", "São Paulo", "Osaka".',
        required: true,
    )]
    #[NotBlank]
    public string $origin_city;

    #[SchemaProperty(
        description: 'ISO 3166-1 alpha-2 code of the departure country, e.g. "IT", "BR", "JP". Empty string if unsure.',
        required: false,
    )]
    #[Regex('/^([A-Z]{2})?$/')]
    public string $origin_country_code = '';

    #[SchemaProperty(description: 'How many people are travelling. 1 if not stated.', required: true)]
    #[GreaterThanEqual(1)]
    public int $travellers;

    #[SchemaProperty(description: 'Length of stay in nights. 7 if not stated.', required: true)]
    #[GreaterThanEqual(1)]
    public int $nights;

    #[SchemaProperty(description: 'Total budget in euros for flights and hotel together. 0 if not stated.', required: true)]
    #[IsNotNull]
    public int $budget;

    #[SchemaProperty(
        description: 'What the traveller cares about, in their words: where (a country, a city, a region, "somewhere warm"), '
            . 'beaches, culture, food, avoiding rain or heat... Empty string if nothing is stated.',
        required: false,
    )]
    public string $preferences = '';

    /**
     * @return array{origin_city: string, origin_country_code: string, travellers: int, nights: int, budget: int, preferences: string}
     */
    public function toArray(): array
    {
        return [
            'origin_city' => $this->origin_city,
            'origin_country_code' => $this->origin_country_code,
            'travellers' => $this->travellers,
            'nights' => $this->nights,
            'budget' => $this->budget,
            'preferences' => $this->preferences,
        ];
    }
}
