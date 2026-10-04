<?php

declare(strict_types=1);

namespace App\Trips;

use App\Models\Trip;
use Illuminate\Support\Facades\DB;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Persistence\DatabasePersistence;
use NeuronBook\TripPlanner\Requests\DecisionRequest;
use NeuronBook\TripPlanner\Requests\PaymentAuthorizationRequest;
use NeuronBook\TripPlanner\TripServices;
use NeuronBook\TripPlanner\TripState;
use NeuronBook\TripPlanner\TripWorkflow;

/**
 * The one place that runs a trip's workflow and records what happened.
 *
 * Every call rebuilds TripWorkflow from the trip ID alone: the workflow's
 * durable state lives in workflow_store (DatabasePersistence), so any worker
 * can pick up any trip. After each segment the state is projected into the
 * trips table, which is all the API ever reads.
 */
final readonly class TripRunner
{
    public function __construct(
        private TripServices $services,
        private string $authorizationWindow = '+15 minutes',
    ) {
    }

    public function start(Trip $trip): void
    {
        $this->record($trip, $this->workflow($trip)->run());
    }

    /**
     * Deliver the traveller's answer - or, with null, nothing, which lets an
     * expired payment hold settle itself.
     *
     * @param array<string, mixed>|null $payload
     */
    public function answer(Trip $trip, ?array $payload): void
    {
        $state = $this->workflow($trip)
            ->run(ExecutionRequest::resume($payload, expectedRunId: $trip->run_id, expectedExecutionAttempt: $trip->execution_attempt));

        $this->record($trip, $state);
    }

    /** A failed run recovers with a plain run(): completed steps and memoized bookings are reused. */
    public function retry(Trip $trip): void
    {
        $this->record($trip, $this->workflow($trip)->run());
    }

    private function workflow(Trip $trip): TripWorkflow
    {
        return TripWorkflow::make(
            tripId: $trip->id,
            services: $this->services,
            ask: $trip->ask,
            today: $trip->created_at->toDateString(),
            authorizationWindow: $this->authorizationWindow,
        )// DatabasePersistence, not EloquentPersistence: the shipped workflow_store table has a
        // composite primary key and no id, which the Eloquent model cannot update (neuron-laravel 2.0.0).
        // It keeps the PDO it is given, so take Laravel's current one on every call.
        ->setPersistence(new DatabasePersistence(DB::connection()->getPdo()));
    }

    private function record(Trip $trip, TripState $state): void
    {
        $trip->fill([
            'status' => match (true) {
                $state->isInterrupted() => Trip::WAITING,
                default => Trip::FINISHED,
            },
            'phase' => null,
            'pending' => $state->isInterrupted() ? $this->pending($state) : null,
            'summary' => [
                'request' => $state->get('request'),
                'origin' => $state->get('origin'),
                'dates' => $state->get('dates'),
                'window' => $state->get('window'),
                'selection' => $state->get('selection'),
                'bookings' => $state->bookings(),
                'feedback' => ['window' => $state->feedback('window'), 'offers' => $state->feedback('offers')],
            ],
            'outcome' => $state->outcome(),
            'note' => $state->get('note'),
            'error' => null,
            'run_id' => $state->getRunId(),
            'execution_attempt' => $state->getExecutionAttempt(),
        ])->save();
    }

    /**
     * The open question, shaped for a UI. The request objects themselves stay
     * inside the workflow; the API only ever shows this projection.
     *
     * @return array<string, mixed>
     */
    private function pending(TripState $state): array
    {
        $request = $state->getInterruptRequest();

        return match (true) {
            $request instanceof PaymentAuthorizationRequest => [
                'type' => 'payment',
                'amount' => $request->getAmount(),
                'currency' => $request->getCurrency(),
                'lines' => $request->getLines(),
                'expires_at' => $request->getExpiresAt()?->format(\DATE_ATOM),
            ],
            $request instanceof DecisionRequest => [
                'type' => 'decision',
                'stage' => $request->getStage(),
                'message' => $request->getMessage(),
                'details' => $request->getDetails(),
            ],
            default => ['type' => 'unknown', 'message' => $request?->getMessage()],
        };
    }
}
