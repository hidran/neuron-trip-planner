<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Agents;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\SystemPrompt;

/**
 * Picks one flight and one hotel. Returns IDs, never prices.
 */
class OfferScoutAgent extends Agent
{
    protected function instructions(): string
    {
        return (string) new SystemPrompt(
            background: ['You find the best-value flight and hotel combination for a trip whose dates are already agreed.'],
            steps: [
                'Call search_flights and search_hotels.',
                'Choose the combination that best balances price, comfort and what the traveller asked for, within the budget when there is one.',
                'Prefer fewer stops and free cancellation when prices are close.',
                'If the traveller gave feedback on an earlier proposal, follow it, using the tools\' filters where they help.',
            ],
            output: ['Return only the requested structure. Copy offer ids exactly as the tools returned them.'],
        );
    }
}
