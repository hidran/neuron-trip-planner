<?php

declare(strict_types=1);

namespace NeuronBook\TripPlannerLive\Api;

/**
 * Tourist attractions from OpenStreetMap, through the Overpass API - free, no key.
 *
 * The query asks for named places tagged as attractions, museums, viewpoints,
 * galleries, zoos or theme parks around a point. Places that carry a Wikidata
 * link are the ones the map's own volunteers consider notable, so they come
 * first.
 */
final class Overpass
{
    private const string ENDPOINT = 'https://overpass-api.de/api/interpreter';

    public function __construct(private readonly Http $http)
    {
    }

    /**
     * @return list<array{name: string, kind: string, notable: bool}>
     * @throws ApiUnavailable
     */
    public function attractions(float $latitude, float $longitude, int $radiusMetres = 6000, int $limit = 12): array
    {
        $query = \sprintf(
            '[out:json][timeout:20];nwr(around:%d,%.5F,%.5F)["tourism"~"^(attraction|museum|viewpoint|gallery|zoo|theme_park)$"]["name"];out tags 80;',
            $radiusMetres,
            $latitude,
            $longitude,
        );

        $data = $this->http->getJson(self::ENDPOINT, ['data' => $query]);

        /** @var list<array{tags?: array<string, string>}> $elements */
        $elements = $data['elements'] ?? [];
        $seen = [];

        foreach ($elements as $element) {
            $tags = $element['tags'] ?? [];
            $name = $tags['name:en'] ?? $tags['name'] ?? '';

            if ($name === '' || isset($seen[$name])) {
                continue;
            }

            $seen[$name] = [
                'name' => $name,
                'kind' => \str_replace('_', ' ', $tags['tourism'] ?? 'attraction'),
                'notable' => isset($tags['wikidata']) || isset($tags['wikipedia']),
            ];
        }

        $places = \array_values($seen);
        \usort($places, static fn (array $a, array $b): int => [$b['notable'], $a['name']] <=> [$a['notable'], $b['name']]);

        return \array_slice($places, 0, $limit);
    }
}
