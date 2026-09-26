<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner;

use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\Workflow;
use NeuronBook\TripPlanner\Nodes\AuthorizeNode;
use NeuronBook\TripPlanner\Nodes\BookingNode;
use NeuronBook\TripPlanner\Nodes\IntakeNode;
use NeuronBook\TripPlanner\Nodes\OffersNode;
use NeuronBook\TripPlanner\Nodes\WindowNode;

/**
 * Plan and book a trip to Mexico, with a human at every decision that matters.
 *
 *   StartEvent ─► IntakeNode ─► WindowNode ⟲ ─► OffersNode ⟲ ─► AuthorizeNode ⟲ ─► BookingNode ─► Stop
 *                  (agent)      (agent+tool)     (agent+tools)    (quote, no LLM)     (saga)
 *                               human #1         human #2         human #3
 *
 * The trip ID is the workflow ID, so any process that can build
 * TripWorkflow::make(tripId: ..., services: ...) and reach the same
 * persistence can continue the trip: the CLI today, a queue job or an HTTP
 * endpoint tomorrow. The request text and "today" only matter on the first
 * run - a continuation restores the persisted state.
 *
 * @extends Workflow<TripState>
 */
class TripWorkflow extends Workflow
{
    public function __construct(
        private readonly string $tripId,
        private readonly TripServices $services,
        string $ask = '',
        ?string $today = null,
        private readonly string $authorizationWindow = '+15 minutes',
    ) {
        parent::__construct(state: new TripState([
            'ask' => $ask,
            'today' => $today ?? \date('Y-m-d'),
        ]));
    }

    public function workflowId(): ?string
    {
        return "trip:{$this->tripId}";
    }

    /**
     * @return NodeInterface[]
     */
    protected function nodes(): array
    {
        return [
            new IntakeNode($this->services),
            new WindowNode($this->services),
            new OffersNode($this->services),
            new AuthorizeNode($this->services, $this->authorizationWindow),
            new BookingNode($this->services),
        ];
    }
}
