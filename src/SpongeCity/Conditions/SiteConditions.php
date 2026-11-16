<?php

declare(strict_types=1);

namespace App\SpongeCity\Conditions;

use App\SpongeCity\Geo\DataSource;
use Core\Configurator\Product\SpongeCity\Impact\Polygon;

/**
 * What official data says about the ground of a site beyond what is drawn: the
 * infiltration potential (areas of plan coordinates in metres, x east, z south) and
 * the sources of every official layer the site was built from. Context for the view
 * (an overlay) and for the agent; not part of the balance.
 *
 * @phpstan-type Area array{points: list<array{float, float}>, holes: list<list<array{float, float}>>, potential: string, depth: string, facility: string}
 */
final readonly class SiteConditions
{
    public const string INFILTRATION = 'infiltration';
    public const string SOURCES = 'sources';
    public const string POINTS = 'points';
    /** areas of the map that are not this class, inside its outline */
    public const string HOLES = 'holes';
    public const string POTENTIAL = 'potential';
    public const string DEPTH = 'depth';
    public const string FACILITY = 'facility';

    /**
     * @param list<Area>       $infiltration
     * @param list<DataSource> $sources
     */
    public function __construct(
        public array $infiltration = [],
        public array $sources = [],
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    /**
     * Share of an outline (plan millimetres) in each infiltration class, sampled on a grid.
     *
     * @param list<array{0: int|float, 1: int|float}> $outline
     *
     * @return array<string, float> potential → share 0…1 (only classes that occur; the rest is unmapped)
     */
    public function infiltrationShares(array $outline, int $samplesPerSide = 40): array
    {
        if (count($outline) < 3 || [] === $this->infiltration) {
            return [];
        }
        // the areas in millimetres, like the outline
        $millimetres = static fn (array $ring): array => array_map(static fn (array $p): array => [$p[0] * 1000, $p[1] * 1000], $ring);
        $areas = array_map(static fn (array $a): array => [$millimetres($a[self::POINTS]), array_map($millimetres, $a[self::HOLES] ?? []), $a[self::POTENTIAL]], $this->infiltration);
        $xs = array_column($outline, 0);
        $zs = array_column($outline, 1);
        [$minX, $maxX, $minZ, $maxZ] = [min($xs), max($xs), min($zs), max($zs)];
        $counts = [];
        $inside = 0;
        for ($i = 0; $i < $samplesPerSide; ++$i) {
            for ($j = 0; $j < $samplesPerSide; ++$j) {
                $x = $minX + ($i + 0.5) * ($maxX - $minX) / $samplesPerSide;
                $z = $minZ + ($j + 0.5) * ($maxZ - $minZ) / $samplesPerSide;
                if (!Polygon::contains($outline, $x, $z)) {
                    continue;
                }
                ++$inside;
                foreach ($areas as [$polygon, $holes, $potential]) {
                    if (Polygon::contains($polygon, $x, $z) && [] === array_filter($holes, static fn (array $hole): bool => Polygon::contains($hole, $x, $z))) {
                        $counts[$potential] = ($counts[$potential] ?? 0) + 1;
                        break;
                    }
                }
            }
        }

        return 0 === $inside ? [] : array_map(static fn (int $n): float => round($n / $inside, 3), $counts);
    }

    /**
     * @return array{infiltration: list<Area>, sources: list<array<string, string>>}
     */
    public function toArray(): array
    {
        return [
            self::INFILTRATION => $this->infiltration,
            self::SOURCES => array_map(static fn (DataSource $s): array => $s->toArray(), $this->sources),
        ];
    }
}
