<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Agents;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\SystemPrompt;

/**
 * Turns "two of us from Milan, ten days, about 5000 euros" into a TripRequest.
 * No tools: reading is its whole job.
 *
 * Like every agent here it declares no provider: TripServices::wire() injects
 * one, so the same classes run under the CLI, under Laravel, and in tests.
 */
class IntakeAgent extends Agent
{
    protected function instructions(): string
    {
        return (string) new SystemPrompt(
            background: ['You read a traveller\'s request for a trip anywhere in the world and record its facts.'],
            steps: [
                'Find the city the traveller departs from, and its country code if you can tell.',
                'Find the number of travellers, the length of stay in nights and the total budget in euros.',
                'Use the stated defaults for anything the traveller did not say.',
            ],
            output: ['Return only the requested structure. Never invent preferences the traveller did not express.'],
        );
    }
}
