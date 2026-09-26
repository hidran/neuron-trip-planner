<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Climate;

use NeuronBook\TripPlanner\Geo\Place;

/**
 * Canned climate for tests and offline demos, hemisphere-aware: summer is
 * June to August in the north and December to February in the south.
 */
final class FixedClimate implements ClimateSource
{
    public function monthly(Place $place): array
    {
        $rainNorth = [45, 30, 25, 30, 70, 180, 140, 150, 190, 200, 110, 60];
        $out = [];

        foreach (\array_keys($rainNorth) as $i) {
            // Six months out of phase below the equator.
            $phase = $place->isSouthernHemisphere() ? ($i + 6) % 12 : $i;
            $out[self::MONTHS[$i + 1]] = [
                'avg_max_c' => 18.0 + 12.0 * \sin(($phase - 3) / 12 * 2 * \M_PI) + 6.0,
                'rain_mm' => $rainNorth[$phase],
                'rainy_days' => (int) \round($rainNorth[$phase] / 8),
                'humidity_pct' => $rainNorth[$phase] > 100 ? 82 : 68,
            ];
        }

        return $out;
    }
}
