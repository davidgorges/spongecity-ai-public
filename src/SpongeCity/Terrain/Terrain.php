<?php

declare(strict_types=1);

namespace App\SpongeCity\Terrain;

use App\SpongeCity\Geo\DataSource;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;

/**
 * The ground of a plan: heights relative to its origin (the searched point), a fine
 * grid over the site and a coarse one over the real surroundings. Beyond those the
 * view's generic city is flat, so the heights fade to the origin's level between
 * FADE_INNER and FADE_OUTER from the centre. Shared by the site (where upright objects
 * stand: SiteGrounding), the agent (TerrainAnalysis) and the 3D view (terrain.js).
 */
final readonly class Terrain
{
    public const string REFERENCE = 'reference';
    public const string FADE_INNER = 'fadeInner';
    public const string FADE_OUTER = 'fadeOuter';
    public const string GRIDS = 'grids';

    /** the real surroundings reach 350 m (ImportSite::SURROUNDINGS_RADIUS_METRES) */
    public const float FADE_INNER_METRES = 300.0;
    public const float FADE_OUTER_METRES = 350.0;
    private const float MILLIMETRES_PER_METRE = 1000.0;
    /** where the ground under an outline is read: every metre along its edges */
    private const float EDGE_STEP_METRES = 1.0;

    public function __construct(
        /** the origin's height above sea level (DHHN2016), metres */
        public float $reference,
        public TerrainGrid $fine,
        public TerrainGrid $coarse,
        public DataSource $source,
    ) {
    }

    /** metres above (or below) the origin at plan metres x/z */
    public function heightAt(float $x, float $z): float
    {
        $height = $this->fine->covers($x, $z) ? $this->fine->heightAt($x, $z) : $this->coarse->heightAt($x, $z);
        $r = hypot($x, $z);
        if ($r <= self::FADE_INNER_METRES) {
            return $height;
        }
        $t = min(1.0, ($r - self::FADE_INNER_METRES) / (self::FADE_OUTER_METRES - self::FADE_INNER_METRES));

        return $height * (1 - $t * $t * (3 - 2 * $t));
    }

    /**
     * Where an object stands, in plan millimetres relative to the origin: the lowest
     * ground under its outline (or at its position), so no wall floats on a slope.
     *
     * @param array<string, mixed> $object a site object
     */
    public function groundOf(array $object): int
    {
        $outline = array_map(static fn (array $p): array => [$p[0] / self::MILLIMETRES_PER_METRE, $p[1] / self::MILLIMETRES_PER_METRE], Site::points($object));
        if ([] === $outline) {
            return (int) round($this->heightAt((float) ($object[O::X] ?? 0) / self::MILLIMETRES_PER_METRE, (float) ($object[O::Z] ?? 0) / self::MILLIMETRES_PER_METRE) * self::MILLIMETRES_PER_METRE);
        }
        // along every edge, not only at the corners: a dip between two corners would show under the wall
        $lowest = INF;
        foreach ($outline as $i => [$ax, $az]) {
            [$bx, $bz] = $outline[($i + 1) % count($outline)];
            $steps = max(1, (int) ceil(hypot($bx - $ax, $bz - $az) / self::EDGE_STEP_METRES));
            for ($k = 0; $k < $steps; ++$k) {
                $lowest = min($lowest, $this->heightAt($ax + ($bx - $ax) * $k / $steps, $az + ($bz - $az) * $k / $steps));
            }
        }

        return (int) round($lowest * self::MILLIMETRES_PER_METRE);
    }

    /**
     * @return array<string, mixed> for the 3D view
     */
    public function toArray(): array
    {
        return [
            self::REFERENCE => round($this->reference, 2),
            self::FADE_INNER => self::FADE_INNER_METRES,
            self::FADE_OUTER => self::FADE_OUTER_METRES,
            self::GRIDS => [$this->fine->toArray(), $this->coarse->toArray()],
        ];
    }
}
