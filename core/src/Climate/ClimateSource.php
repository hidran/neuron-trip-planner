<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Climate;

use NeuronBook\TripPlanner\Geo\Place;

/**
 * Month-by-month weather for a place: what a traveller means by
 * "the best period".
 */
interface ClimateSource
{
    public const array MONTHS = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
        7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
    ];

    /**
     * @return array<string, array{avg_max_c: float, rain_mm: int, rainy_days: int, humidity_pct: int}>
     *         keyed by month name, January first
     */
    public function monthly(Place $place): array;
}
