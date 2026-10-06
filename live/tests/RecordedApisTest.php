<?php

declare(strict_types=1);

namespace NeuronBook\TripPlannerLive\Tests;

use NeuronBook\TripPlannerLive\Api\Http;
use NeuronBook\TripPlannerLive\Api\LiveApis;
use NeuronBook\TripPlannerLive\Api\ReplayHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * The API clients against REAL responses, recorded by bin/record-fixtures.php.
 *
 * The other tests use fixtures written by hand to each service's documented
 * shape, which proves the clients do what they were designed to do. This one
 * proves the design matches what the services actually send. It asserts
 * shapes, never values: a population or an exchange rate changes daily.
 */
final class RecordedApisTest extends TestCase
{
    private LiveApis $apis;

    protected function setUp(): void
    {
        $directory = __DIR__ . '/fixtures/recorded';

        if (\glob("{$directory}/*.json") === [] || \glob("{$directory}/*.json") === false) {
            self::markTestSkipped('No recorded responses yet: run `php bin/record-fixtures.php` online, once.');
        }

        $this->apis = LiveApis::over(new Http(new ReplayHttpClient($directory)));
    }

    public function testCountry(): void
    {
        $portugal = $this->apis->countries->byCode('PT');

        self::assertNotNull($portugal);
        self::assertSame('Portugal', $portugal['country']);
        self::assertSame('EUR', $portugal['currencies'][0]['code']);
        self::assertNotSame('', $portugal['capital']);
    }

    public function testExchangeRate(): void
    {
        $rate = $this->apis->rates->convert(1000.0, 'EUR', 'JPY');

        self::assertGreaterThan(0, $rate['rate']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $rate['date']);
    }

    public function testHolidays(): void
    {
        $holidays = $this->apis->holidays->between('PT', '2026-01-01', '2026-12-31');

        self::assertNotEmpty($holidays);
        self::assertArrayHasKey('date', $holidays[0]);
        self::assertNotSame('', $holidays[0]['name']);
    }

    public function testGuide(): void
    {
        $guide = $this->apis->guide->summary('Lisbon');

        self::assertNotNull($guide);
        self::assertNotSame('', $guide['extract']);
    }

    public function testAdvisory(): void
    {
        $advisory = $this->apis->advisories->forCountry('PT');

        self::assertNotNull($advisory);
        self::assertGreaterThanOrEqual(0, $advisory['score']);
        self::assertLessThanOrEqual(5, $advisory['score']);
    }

    public function testAttractions(): void
    {
        $found = $this->apis->attractions->attractions(38.71667, -9.13333);

        self::assertNotEmpty($found);
        self::assertNotSame('', $found[0]['name']);
    }
}
