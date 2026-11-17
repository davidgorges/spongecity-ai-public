<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo\Hamburg;

use App\SpongeCity\Geo\DataSource;
use App\SpongeCity\Geo\GeoPoint;
use App\SpongeCity\Geo\UtmPlanTransform;
use App\SpongeCity\Terrain\Terrain;
use App\SpongeCity\Terrain\TerrainGrid;
use Psr\Log\LoggerInterface;

/**
 * The ground around a site from Hamburg's terrain model (DGM1), on two grids: a fine one
 * for the site, a coarse one for the surroundings.
 */
final readonly class HamburgTerrain
{
    public const string SOURCE = 'terrain';
    /** the site's grid: past the lawn (112 m) at 2 m; the surroundings' grid: 4 m, a bit past where the heights fade out */
    public const float FINE_HALF_METRES = 120.0;
    public const float FINE_SPACING_METRES = 2.0;
    public const float COARSE_HALF_METRES = 360.0;
    public const float COARSE_SPACING_METRES = 4.0;
    private const float CENTIMETRES_PER_METRE = 100.0;

    public function __construct(
        private HamburgElevation $elevation,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The ground around a site from the terrain model (DGM1), null outside Hamburg or
     * when the download fails (the view stays flat). Every node is the mean of the 1 m
     * heights within its cell, so the grids are smooth enough to drape ground layers on.
     */
    public function around(GeoPoint $centre): ?Terrain
    {
        if (!Hamburg::covers($centre)) {
            return null;
        }
        try {
            // plan metres → UTM is affine over a few hundred metres
            $toUtm = (new UtmPlanTransform($centre))->toUtm(...);
            $reach = self::COARSE_HALF_METRES + self::COARSE_SPACING_METRES;
            $corners = array_map(static fn (array $c): array => $toUtm($c[0] * $reach, $c[1] * $reach), [[-1, -1], [1, -1], [1, 1], [-1, 1]]);
            $tiles = $this->elevation->tiles(min(array_column($corners, 0)), min(array_column($corners, 1)), max(array_column($corners, 0)), max(array_column($corners, 1)));
            if ([] === $tiles) {
                return null;
            }
            $sample = static function (float $x, float $z) use ($toUtm, $tiles): ?float {
                [$e, $n] = $toUtm($x, $z);
                $tile = $tiles[intdiv((int) floor($e), 1000).'_'.intdiv((int) floor($n), 1000)] ?? null;

                return $tile?->heightAt($e, $n);
            };
            $reference = self::cellMean($sample, 0.0, 0.0, self::FINE_SPACING_METRES);
            if (null === $reference) {
                return null;
            }

            return new Terrain(
                $reference,
                self::grid($sample, $reference, self::FINE_HALF_METRES, self::FINE_SPACING_METRES),
                self::grid($sample, $reference, self::COARSE_HALF_METRES, self::COARSE_SPACING_METRES),
                self::source(),
            );
        } catch (\Throwable $exception) {
            $this->logger->warning('Hamburg terrain model unavailable, the ground stays flat', ['exception' => $exception]);

            return null;
        }
    }

    /**
     * @param callable(float, float): ?float $sample metres above sea level at plan metres
     */
    private static function grid(callable $sample, float $reference, float $half, float $spacing): TerrainGrid
    {
        $count = (int) round(2 * $half / $spacing) + 1;
        $heights = [];
        for ($row = 0; $row < $count; ++$row) {
            for ($column = 0; $column < $count; ++$column) {
                $mean = self::cellMean($sample, -$half + $column * $spacing, -$half + $row * $spacing, $spacing);
                // no data (a gap in the model): the origin's level
                $heights[] = null === $mean ? 0 : (int) round(($mean - $reference) * self::CENTIMETRES_PER_METRE);
            }
        }

        return new TerrainGrid(-$half, -$half, $spacing, $count, $count, $heights);
    }

    /**
     * The mean of the 1 m heights in a cell around a node.
     *
     * @param callable(float, float): ?float $sample
     */
    private static function cellMean(callable $sample, float $x, float $z, float $spacing): ?float
    {
        $steps = max(1, (int) round($spacing));
        $sum = 0.0;
        $n = 0;
        for ($i = 0; $i < $steps; ++$i) {
            for ($j = 0; $j < $steps; ++$j) {
                $h = $sample($x + ($i + 0.5) - $steps / 2, $z + ($j + 0.5) - $steps / 2);
                if (null !== $h) {
                    $sum += $h;
                    ++$n;
                }
            }
        }

        return $n > 0 ? $sum / $n : null;
    }

    public static function source(): DataSource
    {
        return new DataSource(self::SOURCE, 'Digitales Geländemodell DGM1 Hamburg', Hamburg::LGV, HamburgElevation::AS_OF, Hamburg::LICENCE, HamburgElevation::ZIP_URL,
            sprintf('Bare-earth model from airborne laser scanning (1 m, about ±15 cm on open ground), averaged to %.0f m on the site and %.0f m around it. Heights above sea level (DHHN2016). Kerbs, gutters and drains are below its resolution.', self::FINE_SPACING_METRES, self::COARSE_SPACING_METRES));
    }
}
