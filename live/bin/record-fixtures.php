<?php

declare(strict_types=1);

require __DIR__ . '/../run/bootstrap.php';

use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronBook\TripPlannerLive\Api\Http;
use NeuronBook\TripPlannerLive\Api\LiveApis;
use NeuronBook\TripPlannerLive\Api\RecordingHttpClient;

/*
 * Run this ONCE, with the internet on, to capture real responses:
 *
 *   php bin/record-fixtures.php
 *
 * It calls every online API the tools use, for Lisbon and Portugal, and saves
 * each answer under tests/fixtures/recorded/. tests/RecordedApisTest.php then
 * replays them offline and checks that the clients still understand the
 * shape the real services returned. Commit the files; re-run the script when a
 * service changes and a test starts failing.
 *
 * The weather forecast is not recorded: its URL carries today's date, so
 * a replay would never match.
 */

$directory = \dirname(__DIR__) . '/tests/fixtures/recorded';
$apis = LiveApis::over(new Http(new RecordingHttpClient(new CurlHttpClient(timeout: 30.0), $directory)));

// Lisbon, as Open-Meteo's geocoder reports it.
const LISBON = [38.71667, -9.13333];

$steps = [
    'country PT' => static fn () => $apis->countries->byCode('PT'),
    'EUR to JPY' => static fn () => $apis->rates->convert(1000.0, 'EUR', 'JPY'),
    'holidays PT 2026' => static fn () => $apis->holidays->between('PT', '2026-01-01', '2026-12-31'),
    'guide Lisbon' => static fn () => $apis->guide->summary('Lisbon'),
    'advisory PT' => static fn () => $apis->advisories->forCountry('PT'),
    'attractions Lisbon' => static fn () => $apis->attractions->attractions(LISBON[0], LISBON[1]),
];

$failed = 0;

foreach ($steps as $label => $step) {
    try {
        $step();
        echo "recorded  {$label}\n";
    } catch (Throwable $e) {
        ++$failed;
        echo "FAILED    {$label}: {$e->getMessage()}\n";
    }
}

echo "\n" . (\count($steps) - $failed) . ' of ' . \count($steps) . " recorded in {$directory}\n";
exit($failed === 0 ? 0 : 1);
