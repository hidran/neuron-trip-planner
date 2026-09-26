<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Trip;
use App\Trips\Segment;
use App\Trips\TripRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use NeuronAI\Exceptions\StaleWorkflowRunException;
use NeuronAI\Exceptions\WorkflowException;
use Throwable;

/**
 * Runs one segment of a trip: from where it is now to the next question, or
 * to the end. Agents take tens of seconds; the HTTP request that asked for
 * this returned long ago, and the SPA polls the trip for the result.
 */
final class RunTripSegment implements ShouldQueue
{
    use Queueable;

    /** A retry is the traveller's decision - the Retry button - not the queue's. */
    public int $tries = 1;

    public int $timeout = 600;

    /**
     * @param array<string, mixed>|null $payload
     */
    public function __construct(
        public readonly string $tripId,
        public readonly Segment $segment,
        public readonly ?array $payload = null,
    ) {
    }

    public function handle(TripRunner $runner): void
    {
        $trip = Trip::findOrFail($this->tripId);

        try {
            match ($this->segment) {
                Segment::Start => $runner->start($trip),
                Segment::Answer => $runner->answer($trip, $this->payload),
                Segment::Retry => $runner->retry($trip),
            };
        } catch (StaleWorkflowRunException $e) {
            // A redelivered job for a run that has moved on: nothing to do.
            Log::info('Stale trip continuation ignored', ['trip' => $trip->id, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            // In this NeuronAI build the stale-*attempt* fence throws a plain
            // WorkflowException (book Appendix A, item 74). It is a no-op too.
            if ($e instanceof WorkflowException && \str_starts_with($e->getMessage(), 'Stale continuation')) {
                Log::info('Stale trip continuation ignored', ['trip' => $trip->id, 'message' => $e->getMessage()]);

                return;
            }

            // Anything else - a provider timeout, the booking service down - is
            // recorded for the traveller. The run is marked failed, not lost:
            // Retry resumes it with every completed step and booking reused.
            Log::warning('Trip segment failed', ['trip' => $trip->id, 'exception' => $e]);

            $trip->forceFill([
                'status' => Trip::FAILED,
                'phase' => null,
                'error' => $e->getMessage(),
            ])->save();
        }
    }
}
