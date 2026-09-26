<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Geo;

/**
 * A real place, as a geocoder knows it.
 *
 * The model never supplies coordinates. It names a city; the application
 * geocodes it, and from then on everything - weather, distances, offers -
 * uses the place ID and the coordinates that came back.
 */
final class Place
{
    public function __construct(
        // PHP 8.5: `final` on promoted properties - a subclass cannot redefine what a Place is.
        final public readonly int $id,
        final public readonly string $name,
        final public readonly string $country,
        final public readonly string $countryCode,
        final public readonly float $latitude,
        final public readonly float $longitude,
        final public readonly string $region = '',
        final public readonly int $population = 0,
    ) {
    }

    public function label(): string
    {
        return "{$this->name}, {$this->country}";
    }

    public function isSouthernHemisphere(): bool
    {
        return $this->latitude < 0;
    }

    /** Great-circle distance in kilometres (haversine). */
    #[\NoDiscard]
    public function distanceTo(self $other): float
    {
        $rad = \M_PI / 180;
        $dLat = ($other->latitude - $this->latitude) * $rad;
        $dLon = ($other->longitude - $this->longitude) * $rad;
        $a = \sin($dLat / 2) ** 2 + \cos($this->latitude * $rad) * \cos($other->latitude * $rad) * \sin($dLon / 2) ** 2;

        return 6371.0 * 2 * \atan2(\sqrt($a), \sqrt(1 - $a));
    }

    /**
     * What the model sees about a candidate place.
     *
     * @return array{place_id: int, name: string, region: string, country: string, population: int}
     */
    public function summary(): array
    {
        return [
            'place_id' => $this->id,
            'name' => $this->name,
            'region' => $this->region,
            'country' => $this->country,
            'population' => $this->population,
        ];
    }

    /**
     * @return array{id: int, name: string, country: string, countryCode: string, latitude: float, longitude: float, region: string, population: int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'country' => $this->country,
            'countryCode' => $this->countryCode,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'region' => $this->region,
            'population' => $this->population,
        ];
    }

    /**
     * @param array<string, mixed> $a
     */
    public static function fromArray(array $a): self
    {
        return new self(
            id: (int) $a['id'],
            name: (string) $a['name'],
            country: (string) $a['country'],
            countryCode: (string) $a['countryCode'],
            latitude: (float) $a['latitude'],
            longitude: (float) $a['longitude'],
            region: (string) ($a['region'] ?? ''),
            population: (int) ($a['population'] ?? 0),
        );
    }
}
