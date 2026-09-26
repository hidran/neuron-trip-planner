<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Booking;

/**
 * A confirmed booking. Plain data, so it can live in workflow state.
 */
final class Booking
{
    public function __construct(
        public readonly string $reference,
        public readonly string $kind,
        public readonly string $offerId,
        public readonly float $amount,
        public readonly string $description,
    ) {
    }

    /**
     * @return array{reference: string, kind: string, offerId: string, amount: float, description: string}
     */
    public function toArray(): array
    {
        return [
            'reference' => $this->reference,
            'kind' => $this->kind,
            'offerId' => $this->offerId,
            'amount' => $this->amount,
            'description' => $this->description,
        ];
    }

    /**
     * @param array<string, mixed> $a
     */
    public static function fromArray(array $a): self
    {
        return new self((string) $a['reference'], (string) $a['kind'], (string) $a['offerId'], (float) $a['amount'], (string) $a['description']);
    }
}
