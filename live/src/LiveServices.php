<?php

declare(strict_types=1);

namespace NeuronBook\TripPlannerLive;

use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\Toolkits\Tavily\TavilySearchTool;
use NeuronAI\Tools\Toolkits\Tavily\TavilyToolkit;
use NeuronBook\TripPlanner\Agents\OfferScoutAgent;
use NeuronBook\TripPlanner\Agents\SeasonAdvisorAgent;
use NeuronBook\TripPlanner\Booking\SandboxBookingGateway;
use NeuronBook\TripPlanner\Climate\OpenMeteoClimate;
use NeuronBook\TripPlanner\Geo\OpenMeteoPlaces;
use NeuronBook\TripPlanner\Geo\PlaceDirectory;
use NeuronBook\TripPlanner\Travel\Inventory;
use NeuronBook\TripPlanner\Travel\SandboxInventory;
use NeuronBook\TripPlanner\TripServices;
use NeuronBook\TripPlannerLive\Amadeus\AmadeusClient;
use NeuronBook\TripPlannerLive\Amadeus\AmadeusInventory;
use NeuronBook\TripPlannerLive\Api\CachingHttpClient;
use NeuronBook\TripPlannerLive\Api\Http;
use NeuronBook\TripPlannerLive\Api\LiveApis;
use NeuronBook\TripPlannerLive\Api\TracingHttpClient;
use NeuronBook\TripPlannerLive\Tools\ConvertCurrencyTool;
use NeuronBook\TripPlannerLive\Tools\GetPublicHolidaysTool;

/**
 * Builds the TripServices for the live version.
 *
 * It is the only difference between this application and the one in core/.
 * The workflow, the nodes, the agents, the checkpoints, the payment
 * authorisation and the booking saga are all unchanged: TripServices is where
 * a trip gets its capabilities, so adding capabilities means building a
 * different one.
 *
 * What it assembles:
 *
 * - Real geocoding and climate, as in core.
 * - The advisor, who picks the destination, gets the whole TravelResearchToolkit.
 * - The scout, who picks the flight and hotel, gets two of its tools: exchange
 *   rates and holidays. A narrower toolbox is a more reliable agent.
 * - Real flights and hotels when Amadeus keys are set, the sandbox otherwise.
 * - Web search for the advisor when a Tavily key is set - the Neuron
 *   equivalent of the Serper search tool that Python trip planners start from.
 * - Payments and bookings stay a sandbox, always.
 */
final class LiveServices
{
    /**
     * @param array<string, string> $env AMADEUS_CLIENT_ID, AMADEUS_CLIENT_SECRET, AMADEUS_ENV, TAVILY_API_KEY, TRIP_TRACE, TRIP_CACHE_TTL
     * @param ?HttpClientInterface $http replaces the network: tests pass a scripted client
     */
    public static function create(
        string $storageDir,
        AIProviderInterface $provider,
        array $env = [],
        ?HttpClientInterface $http = null,
        ?PlaceDirectory $places = null,
    ): TripServices {
        $client = $http ?? self::network($storageDir, $env);
        $apis = LiveApis::over(new Http($client));

        $places ??= new OpenMeteoPlaces("{$storageDir}/places.json");

        $advisorTools = [new TravelResearchToolkit($places, $apis)];

        if (($env['TAVILY_API_KEY'] ?? '') !== '') {
            // Open web search, for the questions no fixed API answers.
            $advisorTools[] = TavilyToolkit::make($env['TAVILY_API_KEY'], $client)->only([TavilySearchTool::class]);
        }

        return new TripServices(
            provider: $provider,
            places: $places,
            climate: new OpenMeteoClimate("{$storageDir}/climate", (int) \date('Y') - 1),
            inventory: self::inventory($storageDir, $env, $client),
            bookings: new SandboxBookingGateway("{$storageDir}/bookings.json"),
            agentTools: [
                SeasonAdvisorAgent::class => $advisorTools,
                OfferScoutAgent::class => [
                    new TravelResearchToolkit($places, $apis)->only([ConvertCurrencyTool::class, GetPublicHolidaysTool::class]),
                ],
            ],
        );
    }

    /**
     * Whether real flights and hotels are on: both Amadeus keys are set.
     *
     * @param array<string, string> $env
     */
    public static function hasAmadeus(array $env): bool
    {
        return ($env['AMADEUS_CLIENT_ID'] ?? '') !== '' && ($env['AMADEUS_CLIENT_SECRET'] ?? '') !== '';
    }

    /**
     * @param array<string, string> $env
     */
    private static function inventory(string $storageDir, array $env, HttpClientInterface $client): Inventory
    {
        if (!self::hasAmadeus($env)) {
            return new SandboxInventory("{$storageDir}/offers.json");
        }

        return new AmadeusInventory(
            new AmadeusClient(
                new Http($client),
                $env['AMADEUS_CLIENT_ID'],
                $env['AMADEUS_CLIENT_SECRET'],
                "{$storageDir}/amadeus-token.json",
                $env['AMADEUS_ENV'] ?? 'test',
            ),
            "{$storageDir}/amadeus-offers.json",
        );
    }

    /**
     * cURL, remembered on disk for a while, and talkative when asked to be.
     *
     * @param array<string, string> $env
     */
    private static function network(string $storageDir, array $env): HttpClientInterface
    {
        $client = new CachingHttpClient(
            new CurlHttpClient(timeout: 20.0),
            "{$storageDir}/http",
            (int) ($env['TRIP_CACHE_TTL'] ?? 21600),
        );

        return ($env['TRIP_TRACE'] ?? '') === '1'
            ? new TracingHttpClient($client, static function (string $line): void {
                \fwrite(\STDERR, $line);
            })
            : $client;
    }
}
