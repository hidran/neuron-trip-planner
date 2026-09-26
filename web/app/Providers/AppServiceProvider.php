<?php

declare(strict_types=1);

namespace App\Providers;

use App\Trips\TripRunner;
use Illuminate\Support\ServiceProvider;
use NeuronAI\Laravel\AIProviderManager;
use NeuronBook\TripPlanner\TripServices;

class AppServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        // The model comes from config/neuron.php (NEURON_AI_PROVIDER and its
        // key in .env), resolved by the Laravel SDK. Geocoding and weather are
        // real; flights, hotels and payments are the sandbox.
        $this->app->singleton(TripServices::class, static fn ($app): TripServices => TripServices::sandbox(
            (string) config('trips.storage'),
            $app->make(AIProviderManager::class)->driver(),
        ));

        $this->app->singleton(TripRunner::class, static fn ($app): TripRunner => new TripRunner(
            $app->make(TripServices::class),
            (string) config('trips.authorization_window'),
        ));
    }

    public function boot(): void
    {
        //
    }
}
