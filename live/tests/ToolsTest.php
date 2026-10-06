<?php

declare(strict_types=1);

namespace NeuronBook\TripPlannerLive\Tests;

use DateTimeImmutable;
use NeuronAI\Tools\ToolOutput;
use NeuronBook\TripPlanner\Geo\FixedPlaces;
use NeuronBook\TripPlanner\Geo\Place;
use NeuronBook\TripPlannerLive\Api\Http;
use NeuronBook\TripPlannerLive\Api\LiveApis;
use NeuronBook\TripPlannerLive\Tests\Support\ScriptedHttp;
use NeuronBook\TripPlannerLive\Tools\ConvertCurrencyTool;
use NeuronBook\TripPlannerLive\Tools\GetAttractionsTool;
use NeuronBook\TripPlannerLive\Tools\GetCountryInfoTool;
use NeuronBook\TripPlannerLive\Tools\GetDestinationGuideTool;
use NeuronBook\TripPlannerLive\Tools\GetPublicHolidaysTool;
use NeuronBook\TripPlannerLive\Tools\GetTravelAdvisoryTool;
use NeuronBook\TripPlannerLive\Tools\GetWeatherForecastTool;
use PHPUnit\Framework\TestCase;

/**
 * What the model gets back: compact JSON on success, a readable instruction
 * on failure, and never an exception.
 */
final class ToolsTest extends TestCase
{
    private ScriptedHttp $http;
    private LiveApis $apis;
    private FixedPlaces $places;
    private Place $kyoto;

    protected function setUp(): void
    {
        $this->http = new ScriptedHttp();
        $this->apis = LiveApis::over(new Http($this->http));
        $this->places = new FixedPlaces();
        $this->kyoto = \array_first($this->places->search('Kyoto')) ?? throw new \LogicException('No Kyoto');
    }

    public function testCountryInfoReturnsCompactJson(): void
    {
        $this->http->route('#restcountries#', 'restcountries-jp');

        $result = new GetCountryInfoTool($this->apis->countries)('JP');

        self::assertIsString($result);
        self::assertSame('JPY', \json_decode($result, true)['currencies'][0]['code']);
    }

    public function testCountryInfoForAnUnknownCodeTellsTheModelWhatToDo(): void
    {
        $this->http->fail('#restcountries#', 404);

        $result = new GetCountryInfoTool($this->apis->countries)('ZZ');

        self::assertInstanceOf(ToolOutput::class, $result);
        self::assertStringContainsString('two-letter ISO code returned by get_monthly_climate', $result->getText());
    }

    public function testADeadServiceIsAnInstructionNotAStackTrace(): void
    {
        $this->http->fail('#restcountries#', 503);

        $result = new GetCountryInfoTool($this->apis->countries)('JP');

        self::assertInstanceOf(ToolOutput::class, $result);
        self::assertStringContainsString('did not answer', $result->getText());
        self::assertStringContainsString('do not guess', $result->getText());
        self::assertStringNotContainsString('Stack trace', $result->getText());
    }

    public function testConversionIsDoneByTheBankNotTheModel(): void
    {
        $this->http->route('#frankfurter#', 'frankfurter-eur-jpy');

        $result = new ConvertCurrencyTool($this->apis->rates)(6000.0, 'EUR', 'JPY');

        self::assertIsString($result);
        self::assertEquals(1034400.0, \json_decode($result, true)['converted']);
    }

    public function testAnUnpublishedCurrencyIsNotEstimated(): void
    {
        $this->http->fail('#frankfurter#', 404);

        $result = new ConvertCurrencyTool($this->apis->rates)(10.0, 'EUR', 'XAF');

        self::assertInstanceOf(ToolOutput::class, $result);
        self::assertStringContainsString('do not estimate', $result->getText());
    }

    public function testHolidaysComeBackWithTheirNamesAndDates(): void
    {
        $this->http->route('#PublicHolidays/2027/JP#', 'nager-jp-2027');

        $result = new GetPublicHolidaysTool($this->apis->holidays)('JP', '2027-04-27', '2027-05-07');

        self::assertIsString($result);
        $decoded = \json_decode($result, true);
        self::assertSame('2027-04-27 to 2027-05-07', $decoded['period']);
        self::assertCount(4, $decoded['holidays']);
    }

    public function testNoHolidaysIsAnEmptyListNotAnError(): void
    {
        $this->http->route('#PublicHolidays/2027/JP#', 'nager-jp-2027');

        $result = new GetPublicHolidaysTool($this->apis->holidays)('JP', '2027-02-01', '2027-02-10');

        self::assertIsString($result);
        self::assertSame([], \json_decode($result, true)['holidays']);
    }

    public function testBadDatesNeverReachTheNetwork(): void
    {
        $result = new GetPublicHolidaysTool($this->apis->holidays)('JP', '27/04/2027', '2027-05-07');

        self::assertInstanceOf(ToolOutput::class, $result);
        self::assertSame([], $this->http->requests);
    }

    public function testAnUncoveredHolidayCountryIsNotListedFromMemory(): void
    {
        $this->http->fail('#nager#', 404);

        $result = new GetPublicHolidaysTool($this->apis->holidays)('ZZ', '2027-04-27', '2027-05-07');

        self::assertInstanceOf(ToolOutput::class, $result);
        self::assertStringContainsString('do not list any from memory', $result->getText());
    }

    public function testAdvisoryIsReportedWithItsDate(): void
    {
        $this->http->route('#travel-advisory#', 'travel-advisory-jp');

        $result = new GetTravelAdvisoryTool($this->apis->advisories)('JP');

        self::assertIsString($result);
        self::assertSame('2026-09-20 08:00:00', \json_decode($result, true)['updated']);
    }

    public function testForecastCoordinatesComeFromTheDirectoryNotTheModel(): void
    {
        $this->http->route('#open-meteo#', 'open-meteo-forecast-kyoto');
        $tool = new GetWeatherForecastTool($this->places, $this->apis->forecast, new DateTimeImmutable('2026-09-26'));

        $result = $tool($this->kyoto->id, '2026-09-28', '2026-09-30');

        self::assertIsString($result);
        self::assertStringContainsString('latitude=35.02107', $this->http->requests[0]);
        self::assertCount(3, \json_decode($result, true)['days']);
    }

    public function testAPlaceIdNobodyReturnedIsRefused(): void
    {
        $tool = new GetWeatherForecastTool($this->places, $this->apis->forecast, new DateTimeImmutable('2026-09-26'));

        $result = $tool(999999, '2026-09-28', '2026-09-30');

        self::assertInstanceOf(ToolOutput::class, $result);
        self::assertStringContainsString('never one you made up', $result->getText());
        self::assertSame([], $this->http->requests);
    }

    public function testNoForecastIsInventedBeyondTheHorizon(): void
    {
        $tool = new GetWeatherForecastTool($this->places, $this->apis->forecast, new DateTimeImmutable('2026-09-26'));

        $result = $tool($this->kyoto->id, '2027-04-27', '2027-05-07');

        self::assertInstanceOf(ToolOutput::class, $result);
        self::assertStringContainsString('Use get_monthly_climate for typical weather and say that this is not a forecast', $result->getText());
        self::assertSame([], $this->http->requests, 'No request is made for dates no forecast can cover.');
    }

    public function testAttractionsAreListedForAKnownPlace(): void
    {
        $this->http->route('#overpass#', 'overpass-kyoto');

        $result = new GetAttractionsTool($this->places, $this->apis->attractions)($this->kyoto->id);

        self::assertIsString($result);
        self::assertSame('Fushimi Inari Taisha', \json_decode($result, true)['attractions'][0]['name']);
    }

    public function testTheGuideFallsBackToWikipedia(): void
    {
        $this->http
            ->fail('#wikivoyage#', 404)
            ->route('#en\.wikipedia\.org#', 'wikivoyage-kyoto');

        $result = new GetDestinationGuideTool($this->places, $this->apis->guide, $this->apis->encyclopedia)($this->kyoto->id);

        self::assertIsString($result);
        self::assertSame('en.wikipedia.org', \json_decode($result, true)['source']);
    }

    public function testNoGuideAtAllIsNotDescribedFromMemory(): void
    {
        $this->http->fail('#wiki#', 404);

        $result = new GetDestinationGuideTool($this->places, $this->apis->guide, $this->apis->encyclopedia)($this->kyoto->id);

        self::assertInstanceOf(ToolOutput::class, $result);
        self::assertStringContainsString('do not describe the place from memory', $result->getText());
    }
}
