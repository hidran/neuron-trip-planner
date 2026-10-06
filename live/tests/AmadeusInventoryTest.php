<?php

declare(strict_types=1);

namespace NeuronBook\TripPlannerLive\Tests;

use NeuronBook\TripPlanner\Geo\FixedPlaces;
use NeuronBook\TripPlanner\Geo\Place;
use NeuronBook\TripPlannerLive\Amadeus\AmadeusClient;
use NeuronBook\TripPlannerLive\Amadeus\AmadeusInventory;
use NeuronBook\TripPlannerLive\Api\ApiUnavailable;
use NeuronBook\TripPlannerLive\Api\Http;
use NeuronBook\TripPlannerLive\Tests\Support\ScriptedHttp;
use PHPUnit\Framework\TestCase;

/**
 * The Amadeus mapping, against hand-written fixtures in the documented
 * schema. bin/smoke-amadeus.php is the check against the real service.
 */
final class AmadeusInventoryTest extends TestCase
{
    private string $dir;
    private ScriptedHttp $http;
    private AmadeusInventory $inventory;
    private Place $milan;
    private Place $kyoto;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/amadeus-test-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0775, true);

        $this->http = new ScriptedHttp();
        $this->http
            ->route('#POST https://test\.api\.amadeus\.com/v1/security/oauth2/token#', 'amadeus-token')
            ->route('#/v1/reference-data/locations/airports#', 'amadeus-airports');

        $this->inventory = $this->inventory();

        $places = new FixedPlaces();
        $this->milan = \array_first($places->search('Milan')) ?? throw new \LogicException();
        $this->kyoto = \array_first($places->search('Kyoto')) ?? throw new \LogicException();
    }

    protected function tearDown(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    public function testFlightsAreMappedAndOtherCurrenciesAreSkipped(): void
    {
        $this->http->route('#/v2/shopping/flight-offers\?#', 'amadeus-flight-offers');

        $flights = $this->inventory->searchFlights($this->milan, $this->kyoto, '2027-04-27', '2027-05-07', 2);

        // Offer 3 is quoted in USD: skipped, never converted by guesswork.
        self::assertCount(2, $flights);

        $cheapest = $flights[0];
        self::assertStringStartsWith('FL-', $cheapest->id);
        self::assertSame('Qatar Airways', $cheapest->airline);
        self::assertSame(1, $cheapest->stops);
        self::assertSame('DOH', $cheapest->via);
        self::assertSame(14.5, $cheapest->hoursEachWay);
        self::assertSame(790.2, $cheapest->pricePerPerson);
        self::assertSame(1580.4, $cheapest->total());
        self::assertSame(0, $flights[1]->stops);
        self::assertSame('', $flights[1]->via);
    }

    public function testTheSearchAsksForEuroAndTheRightAirports(): void
    {
        $this->http->route('#/v2/shopping/flight-offers\?#', 'amadeus-flight-offers');

        $this->inventory->searchFlights($this->milan, $this->kyoto, '2027-04-27', '2027-05-07', 2);

        $search = \array_values(\array_filter($this->http->requests, static fn (string $r): bool => \str_contains($r, '/v2/shopping/flight-offers')))[0];
        self::assertStringContainsString('originLocationCode=VCE', $search);
        self::assertStringContainsString('departureDate=2027-04-27', $search);
        self::assertStringContainsString('adults=2', $search);
        self::assertStringContainsString('currencyCode=EUR', $search);
    }

    public function testTheTokenIsFetchedOnceAndReused(): void
    {
        $this->http->route('#/v2/shopping/flight-offers\?#', 'amadeus-flight-offers');

        $this->inventory->searchFlights($this->milan, $this->kyoto, '2027-04-27', '2027-05-07', 2);
        $this->inventory()->searchFlights($this->milan, $this->kyoto, '2027-04-27', '2027-05-07', 2);

        self::assertSame(1, $this->http->called('oauth2/token'));
    }

    public function testQuotingRepricesTheKnownOffer(): void
    {
        $this->http
            ->route('#/v2/shopping/flight-offers\?#', 'amadeus-flight-offers')
            ->route('#POST .*/v1/shopping/flight-offers/pricing#', 'amadeus-flight-pricing');
        $flights = $this->inventory->searchFlights($this->milan, $this->kyoto, '2027-04-27', '2027-05-07', 2);

        // A different process, days later: the offer is found by ID in the saved file.
        $quote = $this->inventory()->quoteFlight($flights[0]->id);

        self::assertNotNull($quote);
        self::assertSame($flights[0]->id, $quote->id);
        self::assertSame(806.4, $quote->pricePerPerson, 'The re-priced fare is 1612.80 for two.');
    }

    public function testAnIdTheSearchNeverReturnedHasNoQuote(): void
    {
        self::assertNull($this->inventory->quoteFlight('FL-invented'));
        self::assertNull($this->inventory->quoteHotel('HT-invented'));
    }

    public function testAFareThatIsGoneIsNullButAServerErrorIsNot(): void
    {
        $this->http->route('#/v2/shopping/flight-offers\?#', 'amadeus-flight-offers');
        $flights = $this->inventory->searchFlights($this->milan, $this->kyoto, '2027-04-27', '2027-05-07', 2);

        $this->http->fail('#POST .*/pricing#', 400);
        self::assertNull($this->inventory->quoteFlight($flights[0]->id));

        $broken = new ScriptedHttp();
        $broken->route('#oauth2/token#', 'amadeus-token')->fail('#/pricing#', 503);
        $this->expectException(ApiUnavailable::class);
        $this->inventory($broken)->quoteFlight($flights[0]->id);
    }

    public function testHotelsAreMappedPerPartyNotPerRoom(): void
    {
        $this->http
            ->route('#hotels/by-geocode#', 'amadeus-hotels-by-geocode')
            ->route('#/v3/shopping/hotel-offers\?#', 'amadeus-hotel-offers');

        $hotels = $this->inventory->searchHotels($this->kyoto, '2027-04-28', '2027-05-07', 3);

        self::assertCount(2, $hotels);
        // Cheapest first: 540 / 9 nights = 60 a night per room; three travellers need two rooms.
        self::assertSame('Gion Guest House', $hotels[0]->name);
        self::assertSame(120.0, $hotels[0]->nightlyRate);
        self::assertFalse($hotels[0]->freeCancellation);
        self::assertSame(0, $hotels[0]->stars, 'Amadeus sent no rating: unknown is 0, not invented.');
        self::assertSame('HT-OFFERAAA1', $hotels[1]->id);
        self::assertTrue($hotels[1]->freeCancellation);
        self::assertSame(4, $hotels[1]->stars);
        self::assertSame(9, $hotels[1]->nights);
    }

    public function testAHotelQuoteReadsTheCurrentRate(): void
    {
        $this->http
            ->route('#hotels/by-geocode#', 'amadeus-hotels-by-geocode')
            ->route('#/v3/shopping/hotel-offers\?#', 'amadeus-hotel-offers')
            ->route('#/v3/shopping/offers/OFFERAAA1#', 'amadeus-hotel-offer-aaa1');
        $this->inventory->searchHotels($this->kyoto, '2027-04-28', '2027-05-07', 2);

        $quote = $this->inventory()->quoteHotel('HT-OFFERAAA1');

        self::assertNotNull($quote);
        self::assertSame(110.0, $quote->nightlyRate, '990 over 9 nights, one room for two travellers.');
    }

    public function testNoHotelsAroundAPlaceIsAnEmptyList(): void
    {
        $this->http->route('#hotels/by-geocode#', 'amadeus-hotels-by-geocode');
        $empty = new ScriptedHttp();
        $empty->route('#oauth2/token#', 'amadeus-token')->route('#by-geocode#', 'amadeus-token');

        self::assertSame([], $this->inventory($empty)->searchHotels($this->kyoto, '2027-04-28', '2027-05-07', 2));
    }

    private function inventory(?ScriptedHttp $http = null): AmadeusInventory
    {
        return new AmadeusInventory(
            new AmadeusClient(new Http($http ?? $this->http), 'id', 'secret', "{$this->dir}/token.json"),
            "{$this->dir}/offers.json",
        );
    }
}
