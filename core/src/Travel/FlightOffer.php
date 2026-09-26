<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Travel;

/**
 * A priced, bookable flight. Round trip, all travellers, one currency.
 */
final class FlightOffer
{
    public function __construct(
        public readonly string $id,
        public readonly string $airline,
        public readonly string $origin,
        public readonly string $destination,
        public readonly string $departDate,
        public readonly string $returnDate,
        public readonly int $stops,
        public readonly string $via,
        public readonly float $hoursEachWay,
        public readonly float $pricePerPerson,
        public readonly int $travellers,
    ) {
    }

    public function total(): float
    {
        return \round($this->pricePerPerson * $this->travellers, 2);
    }

    /**
     * A re-priced copy. Ignoring the result would be a silent no-op - exactly
     * the bug #[\NoDiscard] exists to catch.
     */
    #[\NoDiscard('FlightOffer is immutable: use the returned copy')]
    public function withPricePerPerson(float $price): self
    {
        // PHP 8.5: clone with - a copy with one readonly property changed.
        return clone($this, ['pricePerPerson' => \round($price, 2)]);
    }

    /**
     * What the model sees: enough to compare, nothing it could misuse.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'airline' => $this->airline,
            'route' => "{$this->origin} - {$this->destination}",
            'depart' => $this->departDate,
            'return' => $this->returnDate,
            'stops' => $this->stops,
            'via' => $this->via,
            'hours_each_way' => $this->hoursEachWay,
            'price_per_person_eur' => $this->pricePerPerson,
            'total_eur' => $this->total(),
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
            (string) $a['id'], (string) $a['airline'], (string) $a['origin'], (string) $a['destination'],
            (string) $a['departDate'], (string) $a['returnDate'], (int) $a['stops'], (string) $a['via'],
            (float) $a['hoursEachWay'], (float) $a['pricePerPerson'], (int) $a['travellers'],
        );
    }
}
