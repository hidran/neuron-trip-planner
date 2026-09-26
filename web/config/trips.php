<?php

declare(strict_types=1);

return [
    // How long a payment quote is held before the trip ends with nothing booked.
    'authorization_window' => env('TRIPS_AUTHORIZATION_WINDOW', '+15 minutes'),

    // Where the sandbox keeps geocoding results, climate data, offers and bookings.
    'storage' => storage_path('app/trip-planner'),
];
