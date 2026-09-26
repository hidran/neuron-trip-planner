<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TripResource;
use App\Jobs\RunTripSegment;
use App\Models\Trip;
use App\Trips\Segment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The trip planner over HTTP.
 *
 * Every write answers 202 Accepted and queues the work: agents take tens of
 * seconds, and the client polls GET /api/trips/{id} until the trip is
 * waiting for it again or has finished. There is no authentication in this
 * example - the unguessable ULID is the only key to a trip. Put the routes
 * behind your auth before this goes anywhere near real money.
 */
final class TripController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return TripResource::collection(Trip::query()->latest()->limit(20)->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ask' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $trip = Trip::create([
            'ask' => $data['ask'],
            'status' => Trip::WORKING,
            'phase' => 'Reading your request and checking the weather',
        ]);

        RunTripSegment::dispatch($trip->id, Segment::Start);

        return new TripResource($trip)->response()->setStatusCode(202);
    }

    public function show(Trip $trip): TripResource
    {
        return new TripResource($trip);
    }

    /**
     * Answer the question the trip is waiting on. The shape of a valid answer
     * depends on that question, so the rules are chosen from the pending
     * request - an "authorize" sent to a date proposal is a 422, not a guess.
     */
    public function answer(Request $request, Trip $trip): JsonResponse
    {
        if ($trip->status !== Trip::WAITING) {
            return response()->json(['message' => 'This trip is not waiting for an answer.'], 409);
        }

        if ($trip->isWaitingFor('payment') && $this->holdExpired($trip)) {
            // Settle it: an answer-less resume lets the workflow end the trip
            // itself, with nothing booked.
            $this->dispatch($trip, null, 'Releasing the expired quote');

            return response()->json(['message' => 'The quote expired. Nothing was booked.'], 409);
        }

        $payload = $trip->isWaitingFor('payment')
            ? $request->validate([
                'decision' => ['required', Rule::in(['authorize', 'decline'])],
                'amount' => ['required_if:decision,authorize', 'numeric', 'min:0'],
            ])
            : $request->validate([
                'decision' => ['required', Rule::in(['approve', 'revise'])],
                'feedback' => ['required_if:decision,revise', 'nullable', 'string', 'max:1000'],
            ]);

        if (isset($payload['amount'])) {
            $payload['amount'] = (float) $payload['amount'];
        }

        $this->dispatch($trip, $payload, $this->phaseFor($trip, (string) $payload['decision']));

        return new TripResource($trip)->response()->setStatusCode(202);
    }

    public function retry(Trip $trip): JsonResponse
    {
        if ($trip->status !== Trip::FAILED) {
            return response()->json(['message' => 'Only a failed trip can be retried.'], 409);
        }

        $trip->forceFill(['status' => Trip::WORKING, 'phase' => 'Retrying', 'error' => null])->save();
        RunTripSegment::dispatch($trip->id, Segment::Retry);

        return new TripResource($trip)->response()->setStatusCode(202);
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function dispatch(Trip $trip, ?array $payload, string $phase): void
    {
        $trip->forceFill(['status' => Trip::WORKING, 'phase' => $phase, 'pending' => null])->save();

        RunTripSegment::dispatch($trip->id, Segment::Answer, $payload);
    }

    private function holdExpired(Trip $trip): bool
    {
        $expiresAt = $trip->pending['expires_at'] ?? null;

        return \is_string($expiresAt) && Carbon::parse($expiresAt)->isPast();
    }

    private function phaseFor(Trip $trip, string $decision): string
    {
        $stage = $trip->pending['stage'] ?? 'payment';

        return match ([$stage, $decision]) {
            ['window', 'approve'] => 'Searching flights and hotels',
            ['window', 'revise'] => 'Rethinking the destination and dates',
            ['offers', 'approve'] => 'Preparing your quote',
            ['offers', 'revise'] => 'Looking for other flights and hotels',
            ['payment', 'authorize'] => 'Booking your flight and hotel',
            default => 'Wrapping up',
        };
    }
}
