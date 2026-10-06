<?php

declare(strict_types=1);

namespace NeuronBook\TripPlannerLive\Amadeus;

use DateTimeImmutable;
use NeuronBook\TripPlanner\Geo\Place;
use NeuronBook\TripPlanner\Travel\FlightOffer;
use NeuronBook\TripPlanner\Travel\HotelOffer;
use NeuronBook\TripPlanner\Travel\Inventory;
use NeuronBook\TripPlannerLive\Api\ApiUnavailable;

/**
 * Real flight and hotel offers from Amadeus, behind the same Inventory the
 * sandbox implements.
 *
 * Nothing above this class changes: the scout's tools, the offer node's trust
 * checks and the authorisation step all talk to Inventory. That is the point
 * of the interface, and this class is the proof.
 *
 * What it does and does not do:
 *
 * - It SEARCHES and RE-PRICES real offers. The booking gateway stays the
 *   sandbox: Amadeus's self-service flight orders need a consolidator
 *   agreement and a payment, which is not something a demo should reach for.
 * - Prices are requested in EUR; an offer in another currency is skipped,
 *   never silently converted.
 * - A hotel offer is one room's rate, multiplied by the rooms the party
 *   needs (two travellers per room). Amadeus does not return star ratings in
 *   the offers response, so stars and the guest rating are 0, meaning
 *   "unknown", and the scout is told so by the tools.
 * - Amadeus offer IDs are only unique within one response, so every ID handed
 *   to the model is prefixed with a hash of the offer itself. The raw offers
 *   are kept in a JSON file keyed by that ID, which is how quote() works from
 *   a different process days later.
 *
 * Written from Amadeus's published schemas and tested against hand-written
 * fixtures: run bin/smoke-amadeus.php once with your own keys before relying
 * on it.
 */
final class AmadeusInventory implements Inventory
{
    private const int MAX_OFFERS = 8;

    public function __construct(
        private readonly AmadeusClient $api,
        private readonly string $storeFile,
        private readonly string $currency = 'EUR',
    ) {
    }

    public function searchFlights(Place $origin, Place $destination, string $depart, string $return, int $travellers): array
    {
        $from = $this->nearestAirport($origin);
        $to = $this->nearestAirport($destination);

        if ($from === null || $to === null) {
            return [];
        }

        $data = $this->api->get('/v2/shopping/flight-offers', [
            'originLocationCode' => $from,
            'destinationLocationCode' => $to,
            'departureDate' => $depart,
            'returnDate' => $return,
            'adults' => $travellers,
            'currencyCode' => $this->currency,
            'max' => 20,
        ]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $data['data'] ?? [];
        /** @var array<string, string> $carriers */
        $carriers = $data['dictionaries']['carriers'] ?? [];
        $offers = [];

        foreach ($rows as $row) {
            $offer = $this->mapFlight($row, $carriers, $from, $to, $depart, $return, $travellers);

            if ($offer !== null) {
                $offers[] = $offer;
                $this->remember('flights', $offer->id, $offer->toArray(), $row);
            }
        }

        \usort($offers, static fn (FlightOffer $a, FlightOffer $b): int => $a->pricePerPerson <=> $b->pricePerPerson);

        return \array_slice($offers, 0, self::MAX_OFFERS);
    }

    public function searchHotels(Place $destination, string $checkIn, string $checkOut, int $travellers): array
    {
        $list = $this->api->get('/v1/reference-data/locations/hotels/by-geocode', [
            'latitude' => $destination->latitude,
            'longitude' => $destination->longitude,
            'radius' => 12,
            'radiusUnit' => 'KM',
            'hotelSource' => 'ALL',
        ]);

        /** @var list<array{hotelId?: string}> $hotels */
        $hotels = $list['data'] ?? [];
        $ids = \array_slice(\array_values(\array_filter(\array_map(static fn (array $h): string => (string) ($h['hotelId'] ?? ''), $hotels))), 0, 25);

        if ($ids === []) {
            return [];
        }

        $data = $this->api->get('/v3/shopping/hotel-offers', [
            'hotelIds' => \implode(',', $ids),
            'adults' => \min($travellers, 2),
            'checkInDate' => $checkIn,
            'checkOutDate' => $checkOut,
            'currency' => $this->currency,
            'bestRateOnly' => 'true',
        ]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $data['data'] ?? [];
        $offers = [];

        foreach ($rows as $row) {
            $offer = $this->mapHotel($row, $destination, $checkIn, $checkOut, $travellers);

            if ($offer !== null) {
                $offers[] = $offer;
                $this->remember('hotels', $offer->id, $offer->toArray(), $row, [
                    'amadeus_id' => (string) ($row['offers'][0]['id'] ?? ''),
                    'rooms' => self::rooms($travellers),
                ]);
            }
        }

        \usort($offers, static fn (HotelOffer $a, HotelOffer $b): int => $a->nightlyRate <=> $b->nightlyRate);

        return \array_slice($offers, 0, self::MAX_OFFERS);
    }

    public function quoteFlight(string $offerId): ?FlightOffer
    {
        $saved = $this->recall('flights', $offerId);

        if ($saved === null) {
            return null;
        }

        $known = FlightOffer::fromArray($saved['mapped']);

        try {
            $priced = $this->api->post('/v1/shopping/flight-offers/pricing', [
                'data' => ['type' => 'flight-offers-pricing', 'flightOffers' => [$saved['raw']]],
            ]);
        } catch (ApiUnavailable $e) {
            // 4xx: the fare is gone or no longer matches. Anything else is a failure, not an answer.
            if ($e->status !== null && $e->status >= 400 && $e->status < 500) {
                return null;
            }

            throw $e;
        }

        /** @var array<string, mixed>|null $row */
        $row = $priced['data']['flightOffers'][0] ?? null;

        if ($row === null || ($row['price']['currency'] ?? $this->currency) !== $this->currency) {
            return null;
        }

        return $known->withPricePerPerson(((float) ($row['price']['grandTotal'] ?? $row['price']['total'] ?? 0)) / \max(1, $known->travellers));
    }

    public function quoteHotel(string $offerId): ?HotelOffer
    {
        $saved = $this->recall('hotels', $offerId);

        if ($saved === null) {
            return null;
        }

        $known = HotelOffer::fromArray($saved['mapped']);

        try {
            $data = $this->api->get('/v3/shopping/offers/' . \rawurlencode((string) $saved['amadeus_id']));
        } catch (ApiUnavailable $e) {
            if ($e->status !== null && $e->status >= 400 && $e->status < 500) {
                return null;
            }

            throw $e;
        }

        /** @var array<string, mixed> $row */
        $row = $data['data'] ?? [];

        if (($row['available'] ?? true) === false || ($row['offers'][0]['price']['currency'] ?? $this->currency) !== $this->currency) {
            return null;
        }

        $total = (float) ($row['offers'][0]['price']['total'] ?? 0);

        return $known->withNightlyRate(($total / \max(1, $known->nights)) * \max(1, (int) ($saved['rooms'] ?? 1)));
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $carriers
     */
    private function mapFlight(array $row, array $carriers, string $from, string $to, string $depart, string $return, int $travellers): ?FlightOffer
    {
        if (($row['price']['currency'] ?? '') !== $this->currency) {
            return null;
        }

        /** @var list<array{duration?: string, segments?: list<array{departure?: array{iataCode?: string}, arrival?: array{iataCode?: string}}>}> $itineraries */
        $itineraries = $row['itineraries'] ?? [];
        $outbound = $itineraries[0] ?? null;

        if ($outbound === null || ($outbound['segments'] ?? []) === []) {
            return null;
        }

        $segments = $outbound['segments'];
        $stops = \count($segments) - 1;
        $via = \implode(', ', \array_map(static fn (array $s): string => (string) ($s['arrival']['iataCode'] ?? ''), \array_slice($segments, 0, $stops)));
        $code = (string) ($row['validatingAirlineCodes'][0] ?? $segments[0]['carrierCode'] ?? '');
        $total = (float) ($row['price']['grandTotal'] ?? $row['price']['total'] ?? 0);

        if ($total <= 0) {
            return null;
        }

        return new FlightOffer(
            id: 'FL-' . \substr(\sha1(\json_encode($row, \JSON_THROW_ON_ERROR)), 0, 10),
            airline: \ucwords(\strtolower($carriers[$code] ?? $code)),
            origin: $from,
            destination: $to,
            departDate: $depart,
            returnDate: $return,
            stops: $stops,
            via: $via,
            hoursEachWay: self::hours((string) ($outbound['duration'] ?? '')),
            pricePerPerson: \round($total / \max(1, $travellers), 2),
            travellers: $travellers,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapHotel(array $row, Place $destination, string $checkIn, string $checkOut, int $travellers): ?HotelOffer
    {
        if (($row['available'] ?? true) === false) {
            return null;
        }

        /** @var array<string, mixed>|null $offer */
        $offer = $row['offers'][0] ?? null;

        if ($offer === null || ($offer['price']['currency'] ?? '') !== $this->currency) {
            return null;
        }

        $nights = \max(1, (int) new DateTimeImmutable($checkIn)->diff(new DateTimeImmutable($checkOut))->days);
        $total = (float) ($offer['price']['total'] ?? 0);

        if ($total <= 0) {
            return null;
        }

        $rooms = self::rooms($travellers);
        $name = (string) ($row['hotel']['name'] ?? 'Hotel');

        return new HotelOffer(
            id: 'HT-' . (string) ($offer['id'] ?? \substr(\sha1($name . $total), 0, 10)),
            name: \ucwords(\strtolower($name)),
            city: $destination->name,
            stars: (int) ($row['hotel']['rating'] ?? 0),
            guestRating: 0.0,
            checkIn: (string) ($offer['checkInDate'] ?? $checkIn),
            checkOut: (string) ($offer['checkOutDate'] ?? $checkOut),
            nights: $nights,
            nightlyRate: \round(($total / $nights) * $rooms, 2),
            freeCancellation: ($offer['policies']['refundable']['cancellationRefund'] ?? '') === 'REFUNDABLE_UP_TO_DEADLINE',
        );
    }

    /**
     * The IATA code of the airport nearest to a place, asked of Amadeus
     * itself so that "Milan" and "Lisbon" resolve the same way for every
     * traveller.
     */
    private function nearestAirport(Place $place): ?string
    {
        $data = $this->api->get('/v1/reference-data/locations/airports', [
            'latitude' => $place->latitude,
            'longitude' => $place->longitude,
            'radius' => 120,
            'page[limit]' => 1,
            'sort' => 'relevance',
        ]);

        $code = $data['data'][0]['iataCode'] ?? null;

        return \is_string($code) && $code !== '' ? $code : null;
    }

    /** "PT14H30M" -> 14.5 */
    private static function hours(string $iso): float
    {
        if (\preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?$/', $iso, $m) !== 1) {
            return 0.0;
        }

        return \round(((int) ($m[1] ?? 0)) + ((int) ($m[2] ?? 0)) / 60, 1);
    }

    private static function rooms(int $travellers): int
    {
        return \max(1, (int) \ceil($travellers / 2));
    }

    /**
     * @param array<string, mixed> $mapped what the model and the offer node see
     * @param array<string, mixed> $raw what Amadeus returned, needed to re-price it
     * @param array<string, scalar> $extra
     */
    private function remember(string $kind, string $id, array $mapped, array $raw, array $extra = []): void
    {
        $all = $this->load();
        $all[$kind][$id] = ['mapped' => $mapped, 'raw' => $raw, ...$extra];
        $this->save($all);
    }

    /**
     * @return array{raw: array<string, mixed>, mapped: array<string, mixed>, amadeus_id?: string, rooms?: int}|null
     */
    private function recall(string $kind, string $id): ?array
    {
        /** @var array{raw: array<string, mixed>, mapped: array<string, mixed>, amadeus_id?: string, rooms?: int}|null */
        return $this->load()[$kind][$id] ?? null;
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

    /**
     * @param array<string, array<string, mixed>> $all
     */
    private function save(array $all): void
    {
        if (!\is_dir(\dirname($this->storeFile))) {
            \mkdir(\dirname($this->storeFile), 0775, true);
        }

        \file_put_contents($this->storeFile, \json_encode($all, \JSON_THROW_ON_ERROR), \LOCK_EX);
    }
}
