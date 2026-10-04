<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner;

use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\UniqueIdGenerator;
use NeuronBook\TripPlanner\Booking\BookingGateway;
use NeuronBook\TripPlanner\Booking\SandboxBookingGateway;
use NeuronBook\TripPlanner\Climate\ClimateSource;
use NeuronBook\TripPlanner\Climate\OpenMeteoClimate;
use NeuronBook\TripPlanner\Geo\OpenMeteoPlaces;
use NeuronBook\TripPlanner\Geo\PlaceDirectory;
use NeuronBook\TripPlanner\Travel\Inventory;
use NeuronBook\TripPlanner\Travel\SandboxInventory;

/**
 * The live dependencies a trip needs, built by whoever runs the workflow.
 *
 * None of this is persisted. A resumed run is rebuilt from its workflow ID
 * and a fresh TripServices: the factory supplies capability, the store
 * supplies state. The CLI builds one from .env, the Laravel app from its
 * container, the tests from fakes - the workflow cannot tell the difference.
 */
final class TripServices
{
    public function __construct(
        public readonly AIProviderInterface $provider,
        public readonly PlaceDirectory $places,
        public readonly ClimateSource $climate,
        public readonly Inventory $inventory,
        public readonly BookingGateway $bookings,
    ) {
    }

    /**
     * Real geocoding and weather; sandbox flights, hotels and payments.
     */
    public static function sandbox(string $storageDir, AIProviderInterface $provider): self
    {
        return new self(
            provider: $provider,
            places: new OpenMeteoPlaces("{$storageDir}/places.json"),
            climate: new OpenMeteoClimate("{$storageDir}/climate", (int) \date('Y') - 1),
            inventory: new SandboxInventory("{$storageDir}/offers.json"),
            bookings: new SandboxBookingGateway("{$storageDir}/bookings.json"),
        );
    }

    /**
     * Give an agent the provider. Agents in this package declare none of
     * their own, so this is the one place that decides which model runs.
     *
     * @template T of Agent
     * @param T $agent
     * @return T
     */
    public function wire(Agent $agent): Agent
    {
        // v4 never invents an ID: each one-shot agent call gets a thread of its own.
        $agent->setAiProvider($this->provider)->setThreadId(UniqueIdGenerator::generateId('trip_'));

        return $agent;
    }
}
