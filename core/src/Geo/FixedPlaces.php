<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Geo;

/**
 * A small in-memory gazetteer for tests and offline demos.
 *
 * Like the real directory, find() only knows places a search has returned:
 * tests of the trust boundary depend on that.
 */
final class FixedPlaces implements PlaceDirectory
{
    /** @var array<int, Place> */
    private array $seen = [];

    /** @var list<Place> */
    private array $all;

    public function __construct(Place ...$places)
    {
        $this->all = $places !== [] ? \array_values($places) : self::defaults();
    }

    public function search(string $name, ?string $countryCode = null): array
    {
        $needle = \mb_strtolower(\trim($name));

        $found = \array_values(\array_filter(
            $this->all,
            static fn (Place $p): bool => \str_starts_with(\mb_strtolower($p->name), $needle)
                && ($countryCode === null || \strcasecmp($p->countryCode, $countryCode) === 0),
        ));

        foreach ($found as $place) {
            $this->seen[$place->id] = $place;
        }

        return $found;
    }

    public function find(int $id): ?Place
    {
        return $this->seen[$id] ?? null;
    }

    /**
     * @return list<Place>
     */
    public static function defaults(): array
    {
        return [
            new Place(3173435, 'Milan', 'Italy', 'IT', 45.46427, 9.18951, 'Lombardy', 1371498),
            new Place(3169070, 'Rome', 'Italy', 'IT', 41.89193, 12.51133, 'Lazio', 2318895),
            new Place(1857910, 'Kyoto', 'Japan', 'JP', 35.02107, 135.75385, 'Kyoto', 1463723),
            new Place(1850147, 'Tokyo', 'Japan', 'JP', 35.6895, 139.69171, 'Tokyo', 8336599),
            new Place(3531673, 'Cancún', 'Mexico', 'MX', 21.17429, -86.84656, 'Quintana Roo', 888797),
            new Place(2147714, 'Sydney', 'Australia', 'AU', -33.86785, 151.20732, 'New South Wales', 4627345),
            new Place(3451190, 'Rio de Janeiro', 'Brazil', 'BR', -22.90642, -43.18223, 'Rio de Janeiro', 6023699),
        ];
    }
}
