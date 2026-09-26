<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Output;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\GreaterThanEqual;
use NeuronAI\StructuredOutput\Validation\Rules\Regex;

/**
 * When the traveller wants to go, if they said so.
 *
 * Extracted from the original request and again from every piece of
 * feedback, then enforced by WindowNode in code. A timing the traveller
 * states is a constraint, not a suggestion - the advisor's job is to find the
 * best trip inside it, never to replace it with a period it likes better.
 */
class DatePreference
{
    #[SchemaProperty(
        description: 'Earliest acceptable start date, YYYY-MM-DD. "in June" -> the 1st of the next June; '
            . '"second half of June" -> the 15th; "from 5 April" -> that date. Empty string if the text sets no timing.',
        required: true,
    )]
    #[Regex('/^(\d{4}-\d{2}-\d{2})?$/')]
    public string $earliest_start = '';

    #[SchemaProperty(
        description: 'Latest acceptable start date, YYYY-MM-DD. "in June" -> the 30th of that June; '
            . '"on 5 April" -> the same date as earliest_start. Empty string if the text sets no timing.',
        required: true,
    )]
    #[Regex('/^(\d{4}-\d{2}-\d{2})?$/')]
    public string $latest_start = '';

    #[SchemaProperty(
        description: 'Length of stay in nights if the text states it or gives both a start and an end date '
            . '("5 to 15 April" -> 10). 0 if it does not.',
        required: true,
    )]
    #[GreaterThanEqual(0)]
    public int $nights = 0;

    public function hasTiming(): bool
    {
        return $this->earliest_start !== '' || $this->latest_start !== '';
    }
}
