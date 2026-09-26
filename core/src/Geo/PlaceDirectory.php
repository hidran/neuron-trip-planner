<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Geo;

/**
 * Turns names into places, and place IDs back into places.
 *
 * find() only knows places an earlier search() returned. That is the trust
 * boundary for destinations: the advisor can only propose a place ID a tool
 * actually showed it, exactly as the offer scout can only choose offer IDs
 * a search returned.
 */
interface PlaceDirectory
{
    /**
     * Candidates for a name, most populous first.
     *
     * @param string|null $countryCode ISO 3166-1 alpha-2, e.g. "JP"
     * @return list<Place>
     */
    public function search(string $name, ?string $countryCode = null): array;

    public function find(int $id): ?Place;
}
