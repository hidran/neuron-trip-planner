<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Travel;

use DateTimeImmutable;
use NeuronBook\TripPlanner\Geo\Place;

/**
 * Deterministic fake flights and hotels for any pair of cities on Earth.
 *
 * Fares follow the real drivers: great-circle distance, a stop through the
 * hub with the smallest detour on long-haul routes, and the destination's
 * seasons - which are the other way round below the equator. The same search
 * always returns the same offers, so runs are reproducible and testable.
 *
 * Offers are written to a small JSON store when searched, as a real provider
 * keeps them for a while: a later process can re-price them by ID.
 * $repriceFactor simulates a fare that moved between proposal and payment.
 */
final class SandboxInventory implements Inventory
{
    /** Fictional carriers: this is a sandbox, and should never look like a real fare. */
    private const array CARRIERS = [
        ['Aurora Air', 1.15],
        ['Meridian Airways', 1.00],
        ['Polaris Connect', 0.92],
        ['Zephyr Air', 0.85],
    ];

    /** name, latitude, longitude */
    private const array HUBS = [
        ['Dubai', 25.25, 55.36], ['Istanbul', 41.26, 28.74], ['Doha', 25.27, 51.61],
        ['Frankfurt', 50.03, 8.57], ['Madrid', 40.47, -3.56], ['London', 51.47, -0.45],
        ['New York', 40.64, -73.78], ['Atlanta', 33.64, -84.43], ['Panama City', 9.07, -79.38],
        ['Singapore', 1.36, 103.99], ['Hong Kong', 22.31, 113.91], ['Tokyo', 35.55, 139.78],
        ['Johannesburg', -26.14, 28.24], ['São Paulo', -23.43, -46.47], ['Sydney', -33.95, 151.18],
    ];

    private const array HOTELS = [
        ['Casa %s Boutique', 4, 9.1, 150.0, true],
        ['Grand %s Palace', 5, 8.7, 290.0, false],
        ['Hotel %s Centro', 3, 8.2, 85.0, true],
        ['%s Garden Suites', 4, 8.9, 190.0, true],
    ];

    public function __construct(
        private readonly string $storeFile,
        private readonly float $repriceFactor = 1.0,
    ) {
    }

    public function searchFlights(Place $origin, Place $destination, string $depart, string $return, int $travellers): array
    {
        $km = $origin->distanceTo($destination);

        if ($km < 150) {
            return []; // too close to fly
        }

        $hub = $km > 5000 ? $this->bestHub($origin, $destination) : null;
        $season = $this->seasonFactor($destination, $depart);
        $offers = [];

        foreach (self::CARRIERS as $i => [$carrier, $factor]) {
            // One direct option on long-haul routes, at a premium; the rest connect.
            $direct = $hub === null || $i === 0;
            $legKm = $direct ? $km : $origin->distanceTo($hub[0]) + $hub[0]->distanceTo($destination);
            $base = 70 + $legKm * 0.075 + $this->noise("{$origin->id}{$destination->id}{$depart}{$i}", 90);

            $offers[] = new FlightOffer(
                id: 'FL-' . \strtoupper(\substr(\hash('crc32b', "{$origin->id}|{$destination->id}|{$depart}|{$return}|{$travellers}|{$i}"), 0, 6)),
                airline: $carrier,
                origin: $origin->name,
                destination: $destination->name,
                departDate: $depart,
                returnDate: $return,
                stops: $direct ? 0 : 1,
                via: $direct ? '' : $hub[1],
                hoursEachWay: \round($legKm / 830 + 0.7 + ($direct ? 0 : 2.5), 1),
                pricePerPerson: \round($base * $factor * $season * ($direct && $hub !== null ? 1.25 : 1.0), 2),
                travellers: $travellers,
            );
        }

        $this->remember(\array_map(static fn (FlightOffer $o): array => ['type' => 'flight'] + $o->toArray(), $offers));

        return $offers;
    }

    public function searchHotels(Place $destination, string $checkIn, string $checkOut, int $travellers): array
    {
        $nights = (int) new DateTimeImmutable($checkIn)->diff(new DateTimeImmutable($checkOut))->days;
        $rooms = (int) \ceil($travellers / 2);
        $season = $this->seasonFactor($destination, $checkIn);
        $offers = [];

        foreach (self::HOTELS as $i => [$pattern, $stars, $rating, $rate, $refundable]) {
            $nightly = ($rate + $this->noise("{$destination->id}{$checkIn}{$i}", 40)) * $rooms * $season;
            $offers[] = new HotelOffer(
                id: 'HT-' . \strtoupper(\substr(\hash('crc32b', "{$destination->id}|{$checkIn}|{$checkOut}|{$travellers}|{$i}"), 0, 6)),
                name: \sprintf($pattern, $destination->name),
                city: $destination->name,
                stars: $stars,
                guestRating: $rating,
                checkIn: $checkIn,
                checkOut: $checkOut,
                nights: $nights,
                nightlyRate: \round($nightly, 2),
                freeCancellation: $refundable,
            );
        }

        $this->remember(\array_map(static fn (HotelOffer $o): array => ['type' => 'hotel'] + $o->toArray(), $offers));

        return $offers;
    }

    public function quoteFlight(string $offerId): ?FlightOffer
    {
        $row = $this->load()[$offerId] ?? null;

        if ($row === null || $row['type'] !== 'flight') {
            return null;
        }

        $offer = FlightOffer::fromArray($row);

        return $offer->withPricePerPerson($offer->pricePerPerson * $this->repriceFactor);
    }

    public function quoteHotel(string $offerId): ?HotelOffer
    {
        $row = $this->load()[$offerId] ?? null;

        if ($row === null || $row['type'] !== 'hotel') {
            return null;
        }

        $offer = HotelOffer::fromArray($row);

        return $offer->withNightlyRate($offer->nightlyRate * $this->repriceFactor);
    }

    /**
     * The connecting hub that adds the least distance to the journey, or
     * null when every hub sits on top of one end of the route.
     *
     * @return array{Place, string}|null
     */
    private function bestHub(Place $origin, Place $destination): ?array
    {
        $candidates = [];
        foreach (self::HUBS as $i => [$name, $lat, $lon]) {
            $hub = new Place(-1 - $i, $name, '', '', $lat, $lon);
            // A hub on top of either end is not a connection.
            if ($hub->distanceTo($origin) < 300 || $hub->distanceTo($destination) < 300) {
                continue;
            }
            $candidates[] = [$origin->distanceTo($hub) + $hub->distanceTo($destination), $hub, $name];
        }

        \usort($candidates, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        // PHP 8.5: array_first() - no reset(), no [0] on a possibly re-keyed array.
        $best = \array_first($candidates);

        return $best === null ? null : [$best[1], $best[2]];
    }

    /**
     * Peak and shoulder seasons, mirrored in the southern hemisphere.
     */
    private function seasonFactor(Place $destination, string $date): float
    {
        $d = new DateTimeImmutable($date);
        $month = (int) $d->format('n');

        // Christmas and New Year are peak everywhere.
        if (($month === 12 && (int) $d->format('j') >= 18) || ($month === 1 && (int) $d->format('j') <= 6)) {
            return 1.45;
        }

        $summer = $destination->isSouthernHemisphere() ? [12, 1, 2] : [7, 8];
        $shoulder = $destination->isSouthernHemisphere() ? [3, 11] : [6, 9];

        return match (true) {
            \in_array($month, $summer, true) => 1.25,
            \in_array($month, $shoulder, true) => 1.10,
            default => 1.0,
        };
    }

    /** A stable pseudo-random amount in [0, $range) derived from a key. */
    private function noise(string $key, int $range): float
    {
        return (float) (\hexdec(\substr(\hash('crc32b', $key), 0, 6)) % $range);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function remember(array $rows): void
    {
        $all = $this->load();
        foreach ($rows as $row) {
            $all[(string) $row['id']] = $row;
        }

        if (!\is_dir(\dirname($this->storeFile))) {
            \mkdir(\dirname($this->storeFile), 0775, true);
        }

        \file_put_contents($this->storeFile, \json_encode($all, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR), \LOCK_EX);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function load(): array
    {
        if (!\is_file($this->storeFile)) {
            return [];
        }

        /** @var array<string, array<string, mixed>> */
        return \json_decode((string) \file_get_contents($this->storeFile), true, flags: \JSON_THROW_ON_ERROR);
    }
}
