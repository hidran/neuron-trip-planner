<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Output;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\GreaterThan;
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;
use NeuronAI\StructuredOutput\Validation\Rules\Regex;

/**
 * The season advisor's proposal.
 *
 * Two things are NOT the model's to say. The place is a place_id, which the
 * node looks up in the directory - an ID get_monthly_climate never returned
 * is sent back. And there is no end date: that is start plus the nights the
 * traveller asked for, which is arithmetic, not judgement.
 */
class TravelWindow
{
    #[SchemaProperty(description: 'The place_id of the chosen city, exactly as get_monthly_climate returned it.', required: true)]
    #[GreaterThan(0)]
    public int $destination_place_id;

    #[SchemaProperty(description: 'First day of the trip, formatted YYYY-MM-DD.', required: true)]
    #[Regex('/^\d{4}-\d{2}-\d{2}$/')]
    public string $start_date;

    #[SchemaProperty(
        description: 'The weather to expect, from the climate data: typical maximum temperature, rain, humidity.',
        required: true,
    )]
    #[NotBlank]
    public string $weather_summary;

    #[SchemaProperty(
        description: 'Why this place and this period fit what the traveller asked for, in two or three sentences.',
        required: true,
    )]
    #[NotBlank]
    public string $reasoning;
}
