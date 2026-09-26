<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Travel;

use NeuronBook\TripPlanner\Geo\Place;

/**
 * Where offers come from.
 *
 * The sandbox implementation generates them; a real one would call a
 * provider such as Amadeus or Duffel. Either way the contract is the one real
 * travel APIs have: search returns offers with IDs, and an ID is re-priced
 * before you pay, because fares move.
 */
interface Inventory
{
    /**
     * @return list<FlightOffer>
     */
    public function searchFlights(Place $origin, Place $destination, string $depart, string $return, int $travellers): array;

    /**
     * @return list<HotelOffer>
     */
    public function searchHotels(Place $destination, string $checkIn, string $checkOut, int $travellers): array;

    /**
     * The current price of an offer returned by an earlier search, or null
     * if the ID is unknown or the offer is no longer available.
     */
    #[\NoDiscard('a quote is only useful if you read it')]
    public function quoteFlight(string $offerId): ?FlightOffer;

    #[\NoDiscard('a quote is only useful if you read it')]
    public function quoteHotel(string $offerId): ?HotelOffer;
}
