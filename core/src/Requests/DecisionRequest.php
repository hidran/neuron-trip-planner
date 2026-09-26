<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Requests;

use NeuronAI\Workflow\Interrupt\WaitForEventRequest;

/**
 * "Here is a proposal - approve it, or tell me what to change."
 *
 * Used for both the dates and the offers. The request is persisted with the
 * paused run, so everything a UI needs to render it - even days later, in
 * another process - travels in metadata().
 *
 * Answer with ['decision' => 'approve'] or
 * ['decision' => 'revise', 'feedback' => '...'].
 */
class DecisionRequest extends WaitForEventRequest
{
    public const EVENT = 'trip.decision';

    /**
     * @param 'window'|'offers' $stage
     * @param array<string, mixed> $details
     */
    public function __construct(
        protected string $stage,
        protected string $message,
        protected array $details,
    ) {
        parent::__construct(self::EVENT);
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getStage(): string
    {
        return $this->stage;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    /**
     * @return array<string, mixed>
     */
    protected function metadata(): array
    {
        return ['stage' => $this->stage, 'message' => $this->message, 'details' => $this->details];
    }
}
