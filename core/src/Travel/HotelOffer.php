<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Travel;

/**
 * A priced, bookable hotel stay for the whole party.
 */
final class HotelOffer
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $city,
        public readonly int $stars,
        public readonly float $guestRating,
        public readonly string $checkIn,
        public readonly string $checkOut,
        public readonly int $nights,
        public readonly float $nightlyRate,
        public readonly bool $freeCancellation,
    ) {
    }

    public function total(): float
    {
        return \round($this->nightlyRate * $this->nights, 2);
    }

    #[\NoDiscard('HotelOffer is immutable: use the returned copy')]
    public function withNightlyRate(float $rate): self
    {
        return clone($this, ['nightlyRate' => \round($rate, 2)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'stars' => $this->stars,
            'guest_rating' => $this->guestRating,
            'check_in' => $this->checkIn,
            'check_out' => $this->checkOut,
            'nightly_rate_eur' => $this->nightlyRate,
            'total_eur' => $this->total(),
            'free_cancellation' => $this->freeCancellation,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return \get_object_vars($this);
    }

    /**
     * @param array<string, mixed> $a
     */
    public static function fromArray(array $a): self
    {
        return new self(
            (string) $a['id'], (string) $a['name'], (string) $a['city'], (int) $a['stars'],
            (float) $a['guestRating'], (string) $a['checkIn'], (string) $a['checkOut'], (int) $a['nights'],
            (float) $a['nightlyRate'], (bool) $a['freeCancellation'],
        );
    }
}
