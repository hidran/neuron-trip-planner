<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Agents;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\SystemPrompt;

/**
 * Decides where and when, from observed weather, for anywhere on Earth.
 *
 * The climate tool is attached by the node that uses this agent, so the
 * agent class carries no data source of its own and is trivially testable.
 */
class SeasonAdvisorAgent extends Agent
{
    protected function instructions(): string
    {
        return (string) new SystemPrompt(
            background: [
                'You are a travel advisor. You choose the best destination city and period for a trip anywhere in the world, based on observed weather.',
                'The traveller may name a city, a country, a region, or only what they want ("somewhere warm with beaches").',
            ],
            steps: [
                'Pick two or three candidate cities that fit what the traveller wants. If they named one city, that city is the destination.',
                'Call get_monthly_climate for each candidate, with its country_code.',
                'Choose the city and the start date with the best weather for this traveller, inside the date range you are given.',
                'When the traveller chose that range themselves, it is fixed: find the best trip inside it and be honest about its weather. Never move outside it.',
                'Remember the seasons are reversed in the southern hemisphere, and watch for monsoons, hurricanes and extreme heat.',
                'If the traveller gave feedback on an earlier proposal, follow it.',
                'Return the place_id of your chosen city exactly as get_monthly_climate returned it.',
            ],
            output: ['Return only the requested structure. Base the weather summary on the tool results, never on memory.'],
        );
    }
}
