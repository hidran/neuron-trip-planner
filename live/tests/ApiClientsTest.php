<?php

declare(strict_types=1);

namespace NeuronBook\TripPlannerLive\Tests;

use InvalidArgumentException;
use NeuronBook\TripPlannerLive\Api\ApiUnavailable;
use NeuronBook\TripPlannerLive\Api\CachingHttpClient;
use NeuronBook\TripPlannerLive\Api\Http;
use NeuronBook\TripPlannerLive\Api\LiveApis;
use NeuronBook\TripPlannerLive\Tests\Support\ScriptedHttp;
use PHPUnit\Framework\TestCase;

/**
 * Each client turns a service's payload into the few fields a tool returns,
 * and turns the service's failures into one exception type.
 */
final class ApiClientsTest extends TestCase
{
    private ScriptedHttp $http;
    private LiveApis $apis;

    protected function setUp(): void
    {
        $this->http = new ScriptedHttp();
        $this->apis = LiveApis::over(new Http($this->http));
    }

    public function testCountryFactsAreNormalised(): void
    {
        $this->http->route('#restcountries\.com/v3\.1/alpha/JP#', 'restcountries-jp');

        $japan = $this->apis->countries->byCode('jp');

        self::assertSame('Japan', $japan['country']);
        self::assertSame('Tokyo', $japan['capital']);
        self::assertSame([['code' => 'JPY', 'name' => 'Japanese yen', 'symbol' => '¥']], $japan['currencies']);
        self::assertSame(['Japanese'], $japan['languages']);
        self::assertSame('+81', $japan['calling_code']);
        self::assertSame('left', $japan['driving_side']);
        self::assertStringContainsString('/alpha/JP?fields=', $this->http->requests[0]);
    }

    public function testAnUnknownCountryIsNullNotAnError(): void
    {
        $this->http->fail('#restcountries#', 404);

        self::assertNull($this->apis->countries->byCode('ZZ'));
    }

    public function testAServerErrorIsAnApiUnavailable(): void
    {
        $this->http->fail('#restcountries#', 503);

        try {
            $this->apis->countries->byCode('JP');
            self::fail('Expected ApiUnavailable');
        } catch (ApiUnavailable $e) {
            self::assertSame(503, $e->status);
            self::assertFalse($e->isNotFound());
        }
    }

    public function testAnUnroutedHostIsAnApiUnavailableToo(): void
    {
        $this->expectException(ApiUnavailable::class);
        $this->expectExceptionMessage('restcountries.com is unreachable');

        $this->apis->countries->byCode('JP');
    }

    public function testCurrencyConversionUsesTheServicesRate(): void
    {
        $this->http->route('#frankfurter\.dev/v1/latest\?base=EUR&symbols=JPY#', 'frankfurter-eur-jpy');

        $converted = $this->apis->rates->convert(6000, 'eur', 'jpy');

        self::assertSame(172.4, $converted['rate']);
        self::assertEquals(1034400.0, $converted['converted']);
        self::assertSame('2026-09-25', $converted['date']);
    }

    public function testTheSameCurrencyNeedsNoRequest(): void
    {
        self::assertSame(1.0, $this->apis->rates->convert(10, 'EUR', 'EUR')['rate']);
        self::assertSame([], $this->http->requests);
    }

    public function testNonsenseCurrencyCodesAreRejectedBeforeAnyRequest(): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            $this->apis->rates->convert(1, 'EUR', 'yens');
        } finally {
            self::assertSame([], $this->http->requests);
        }
    }

    public function testHolidaysAreFilteredToTheWindow(): void
    {
        $this->http->route('#date\.nager\.at/api/v3/PublicHolidays/2027/JP#', 'nager-jp-2027');

        $holidays = $this->apis->holidays->between('JP', '2027-04-27', '2027-05-07');

        self::assertSame(['2027-04-29', '2027-05-03', '2027-05-04', '2027-05-05'], \array_column($holidays ?? [], 'date'));
        self::assertSame("Children's Day", $holidays[3]['name']);
        self::assertSame('こどもの日', $holidays[3]['local_name']);
    }

    public function testAWindowAcrossNewYearAsksForBothYears(): void
    {
        $this->http
            ->route('#PublicHolidays/2026/JP#', 'nager-jp-2027')
            ->route('#PublicHolidays/2027/JP#', 'nager-jp-2027');

        $this->apis->holidays->between('JP', '2026-12-28', '2027-01-03');

        self::assertSame(1, $this->http->called('PublicHolidays/2026/JP'));
        self::assertSame(1, $this->http->called('PublicHolidays/2027/JP'));
    }

    public function testAnUncoveredCountryHasNoHolidayAnswer(): void
    {
        $this->http->fail('#nager#', 404);

        self::assertNull($this->apis->holidays->between('ZZ', '2027-01-01', '2027-01-02'));
    }

    public function testGuideSummaryIsTrimmedAndLinked(): void
    {
        $this->http->route('#en\.wikivoyage\.org/api/rest_v1/page/summary/Kyoto$#', 'wikivoyage-kyoto');

        $page = $this->apis->guide->summary('Kyoto');

        self::assertSame('Kyoto', $page['title']);
        self::assertStringContainsString('cultural heart of Japan', $page['extract']);
        self::assertSame('https://en.wikivoyage.org/wiki/Kyoto', $page['url']);
        self::assertSame('en.wikivoyage.org', $page['source']);
    }

    public function testADisambiguationPageIsNotAGuide(): void
    {
        $this->http->route('#summary/Rome#', 'wikivoyage-disambiguation');

        self::assertNull($this->apis->guide->summary('Rome'));
    }

    public function testAdvisoryScoreBecomesALevel(): void
    {
        $this->http->route('#travel-advisory\.info/api\?countrycode=JP#', 'travel-advisory-jp');

        $advisory = $this->apis->advisories->forCountry('jp');

        self::assertSame(1.9, $advisory['score']);
        self::assertSame('low risk', $advisory['level']);
        self::assertSame('2026-09-20 08:00:00', $advisory['updated']);
    }

    public function testForecastBecomesOneRowPerDay(): void
    {
        $this->http->route('#api\.open-meteo\.com/v1/forecast#', 'open-meteo-forecast-kyoto');

        $days = $this->apis->forecast->daily(35.02107, 135.75385, '2026-09-28', '2026-09-30');

        self::assertCount(3, $days);
        self::assertSame(['date' => '2026-09-30', 'max_c' => 24.9, 'min_c' => 17.5, 'rain_mm' => 11.6, 'rain_probability_pct' => 80], $days[2]);
    }

    public function testAttractionsAreDedupedAndTheNotableComeFirst(): void
    {
        $this->http->route('#overpass-api\.de/api/interpreter\?data=#', 'overpass-kyoto');

        $found = $this->apis->attractions->attractions(35.02107, 135.75385);

        self::assertSame(['Fushimi Inari Taisha', 'Kiyomizu-dera Temple', 'Kyoto National Museum', 'Small Local Gallery'], \array_column($found, 'name'));
        self::assertTrue($found[0]['notable']);
        self::assertFalse($found[3]['notable']);
        self::assertStringContainsString('around%3A6000%2C35.02107', $this->http->requests[0]);
    }

    public function testTheCacheAnswersTheSecondIdenticalGetFromDisk(): void
    {
        $dir = \sys_get_temp_dir() . '/live-cache-' . \bin2hex(\random_bytes(4));
        $this->http->route('#frankfurter#', 'frankfurter-eur-jpy');
        $apis = LiveApis::over(new Http(new CachingHttpClient($this->http, $dir)));

        $apis->rates->convert(100, 'EUR', 'JPY');
        $apis->rates->convert(250, 'EUR', 'JPY');

        self::assertSame(1, $this->http->called('frankfurter'), 'The second lookup must not reach the network.');
        \exec('rm -rf ' . \escapeshellarg($dir));
    }

    public function testAFailureIsNeverCached(): void
    {
        $dir = \sys_get_temp_dir() . '/live-cache-' . \bin2hex(\random_bytes(4));
        $this->http->fail('#restcountries#', 503);
        $apis = LiveApis::over(new Http(new CachingHttpClient($this->http, $dir)));

        foreach ([1, 2] as $_) {
            try {
                $apis->countries->byCode('JP');
            } catch (ApiUnavailable) {
            }
        }

        self::assertSame(2, $this->http->called('restcountries'));
        \exec('rm -rf ' . \escapeshellarg($dir));
    }
}
