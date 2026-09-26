<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Agents;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\SystemPrompt;

/**
 * Turns "second half of June", "from 5 to 15 April" or "not before Easter"
 * into dates. It only reads; WindowNode is what enforces the result.
 */
class DatePreferenceAgent extends Agent
{
    protected function instructions(): string
    {
        return (string) new SystemPrompt(
            background: ['You read what a traveller wrote and extract WHEN they want to travel, as dates.'],
            steps: [
                'Use the date you are given as today. A month or period without a year means its next occurrence after today.',
                'Convert periods into the earliest and latest acceptable START date of the trip.',
                'If the traveller gives both a start and an end date, also return the number of nights between them.',
                'If the text says nothing about timing, return empty strings and 0.',
            ],
            output: ['Return only the requested structure. Never invent a timing the traveller did not express.'],
        );
    }
}
