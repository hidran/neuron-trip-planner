<?php

declare(strict_types=1);

require __DIR__ . '/../run/bootstrap.php';

use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronBook\TripPlanner\Geo\OpenMeteoPlaces;
use NeuronBook\TripPlannerLive\Amadeus\AmadeusClient;
use NeuronBook\TripPlannerLive\Amadeus\AmadeusInventory;
use NeuronBook\TripPlannerLive\Api\Http;
use NeuronBook\TripPlannerLive\LiveServices;

/*
 * Checks your Amadeus keys and the AmadeusInventory mapping against the real
 * service, before a trip depends on them:
 *
 *   php bin/smoke-amadeus.php [origin] [destination] [days-ahead]
 *   php bin/smoke-amadeus.php Milan Lisbon 45
 *
 * It searches one flight and one hotel, re-prices the first of each, and prints
 * what the planner would show. The AmadeusInventory class was written from the
 * published schemas; this is the script that proves it against the live one.
 */

$env = live_env();

if (!LiveServices::hasAmadeus($env)) {
    exit("Set AMADEUS_CLIENT_ID and AMADEUS_CLIENT_SECRET in .env first.\n");
}

[$originName, $destinationName, $days] = [$argv[1] ?? 'Milan', $argv[2] ?? 'Lisbon', (int) ($argv[3] ?? 45)];
$storage = \dirname(__DIR__) . '/storage';
$places = new OpenMeteoPlaces("{$storage}/places.json");

$origin = \array_first($places->search($originName)) ?? exit("No place called {$originName}.\n");
$destination = \array_first($places->search($destinationName)) ?? exit("No place called {$destinationName}.\n");

$http = new Http(new CurlHttpClient(timeout: 30.0));
$inventory = new AmadeusInventory(
    new AmadeusClient($http, $env['AMADEUS_CLIENT_ID'], $env['AMADEUS_CLIENT_SECRET'], "{$storage}/amadeus-token.json", $env['AMADEUS_ENV'] ?: 'test'),
    "{$storage}/amadeus-offers.json",
);

$depart = \date('Y-m-d', \strtotime("+{$days} days"));
$return = \date('Y-m-d', \strtotime("+" . ($days + 7) . ' days'));

echo "{$origin->label()} -> {$destination->label()}, {$depart} to {$return}\n\nFLIGHTS\n";
$flights = $inventory->searchFlights($origin, $destination, $depart, $return, 2);

foreach ($flights as $f) {
    \printf("  %-14s %-18s %s -> %s  %d stop(s) %s  %.1fh  %8.2f EUR pp\n", $f->id, $f->airline, $f->origin, $f->destination, $f->stops, $f->via, $f->hoursEachWay, $f->pricePerPerson);
}

echo $flights === [] ? "  none returned\n" : '';
$first = \array_first($flights);

if ($first !== null) {
    $quote = $inventory->quoteFlight($first->id);
    echo '  re-priced first offer: ' . ($quote === null ? 'no longer available' : \sprintf('%.2f EUR pp', $quote->pricePerPerson)) . "\n";
}

echo "\nHOTELS\n";
$hotels = $inventory->searchHotels($destination, $depart, $return, 2);

foreach ($hotels as $h) {
    \printf("  %-22s %-32s %3d*  %8.2f EUR/night  %s\n", $h->id, $h->name, $h->stars, $h->nightlyRate, $h->freeCancellation ? 'free cancellation' : 'non-refundable');
}

echo $hotels === [] ? "  none returned\n" : '';
$first = \array_first($hotels);

if ($first !== null) {
    $quote = $inventory->quoteHotel($first->id);
    echo '  re-priced first offer: ' . ($quote === null ? 'no longer available' : \sprintf('%.2f EUR/night', $quote->nightlyRate)) . "\n";
}
