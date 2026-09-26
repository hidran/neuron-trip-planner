<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RunTripSegment;
use App\Models\Trip;
use App\Trips\Segment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Nobody answered the payment question in time.
 *
 * The deadline belongs to the workflow - it is part of the persisted request
 * - but something has to knock on the door after it passes. An answer-less
 * resume does that: the workflow sees the expired hold, ends the trip, and
 * nothing is booked.
 */
#[Signature('trips:settle-expired')]
#[Description('End trips whose payment hold expired without an answer')]
final class SettleExpiredTrips extends Command
{
    public function handle(): int
    {
        $settled = 0;

        Trip::query()->where('status', Trip::WAITING)->each(function (Trip $trip) use (&$settled): void {
            $expiresAt = $trip->pending['expires_at'] ?? null;

            if (!$trip->isWaitingFor('payment') || !\is_string($expiresAt) || Carbon::parse($expiresAt)->isFuture()) {
                return;
            }

            $trip->forceFill(['status' => Trip::WORKING, 'phase' => 'Releasing the expired quote', 'pending' => null])->save();
            RunTripSegment::dispatch($trip->id, Segment::Answer);
            $settled++;
        });

        $this->info("Settled {$settled} expired trip(s).");

        return self::SUCCESS;
    }
}
