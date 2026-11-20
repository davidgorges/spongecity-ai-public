<?php

declare(strict_types=1);

namespace App\SpongeCity\Placement;

use Core\Configurator\Product\SpongeCity\Impact\GroundClass;
use Core\Configurator\Product\SpongeCity\Impact\ImpactCalculator;
use Core\Configurator\Product\SpongeCity\Impact\Polygon;
use Core\Configurator\Product\SpongeCity\SpongeCityDefinition;
use Core\Configurator\Product\SpongeCity\SpongeCitySku as Sku;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Shade trees where their crown will shade open ground that has none: on free ground (not on a
 * building, path, the plot's edge, a pitch, a playground's fall zone, a planned measure or another
 * tree's root zone), far enough from the walls for the grown crown, the crowns apart. Positions are
 * ranked by the sunlit open ground their crown's shadow falls on at the hour the shade is measured,
 * and the best are measured in the balance itself (shade share with the tree against without).
 * Where the ground lets water soak away and is sealed around, a tree trench (Baumrigole) below it
 * stores the runoff and waters the tree.
 */
#[AsTaggedItem(index: O::NEWTREE)]
final readonly class TreeSiting implements InterventionSiting
{
    /** the grown crown keeps this far from a façade, beyond its radius, metres */
    public const float WALL_GAP_METRES = 1.0;
    /** the planting pit around the trunk must be free ground, metres */
    public const float PIT_RADIUS_METRES = 1.0;
    /** a tree trench's volume where one is suggested, m³: takes the runoff of TREE_TRENCH_CATCHMENT_M2_PER_M3 times as many m² */
    public const int TRENCH_M3 = 12;
    /** positions looked at, every this many metres */
    private const float SAMPLE_METRES = 2.0;
    /** how many of the best ranked positions are measured in the balance, per candidate asked for */
    private const int MEASURED_PER_CANDIDATE = 3;
    /** a tree that adds less shade than this (percentage points of the open ground) is not worth proposing */
    private const float MIN_SHADE_POINTS = 0.05;
    private const float NEAR_RADIUS_METRES = 30.0;
    private const float MILLIMETRES_PER_METRE = 1000.0;
    private const float CENTS_PER_EURO = 100.0;
    private const string ID_PREFIX = 'newtree-';

    public function __construct(private ImpactCalculator $calculator = new ImpactCalculator())
    {
    }

    public function propose(PlacementContext $context, PlacementRequest $request): Proposal
    {
        $ground = $context->ground;
        $height = (float) O::fields(O::NEWTREE)[O::HEIGHT][3];
        $radius = $height * ImpactCalculator::CANOPY_RADIUS_SHARE / self::MILLIMETRES_PER_METRE;
        $others = $this->crowns($context->objects);

        $ranked = [];
        foreach ($this->samples($ground) as $cell) {
            [$x, $z] = $ground->centre($cell);
            if (null !== $request->near && hypot($x - $request->near[0] / self::MILLIMETRES_PER_METRE, $z - $request->near[1] / self::MILLIMETRES_PER_METRE) > self::NEAR_RADIUS_METRES) {
                continue;
            }
            if (!$this->fits($ground, $cell, $radius, $others)) {
                continue;
            }
            $score = $this->sunlitUnderShadow($ground, $x, $z, $height, $radius, $others);
            if ($score > 0) {
                $ranked[] = ['cell' => $cell, 'x' => $x, 'z' => $z, 'score' => $score];
            }
        }
        usort($ranked, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $shortlist = self::apart($ranked, 2 * $radius, $request->count * self::MEASURED_PER_CANDIDATE);

        // the shortlist measured in the balance: what each tree adds to the plan's shade as it is
        $site = new Site($context->objects);
        $base = $this->calculator->calculate($site, $context->stormMillimetres)->metric(ImpactCalculator::METRIC_SHADE)?->design ?? 0.0;
        $groundArea = $this->calculator->survey($site)?->groundArea() ?? 0.0;
        $measured = [];
        foreach ($shortlist as $spot) {
            $tree = $this->tree($context, $spot, $height);
            $with = $this->calculator->calculate(new Site([...$context->objects, $tree]), $context->stormMillimetres)->metric(ImpactCalculator::METRIC_SHADE)?->design ?? 0.0;
            if ($with - $base >= self::MIN_SHADE_POINTS) {
                $measured[] = [...$spot, 'tree' => $tree, 'points' => $with - $base];
            }
        }
        usort($measured, static fn (array $a, array $b): int => $b['points'] <=> $a['points']);

        $candidates = [];
        foreach (array_slice($measured, 0, $request->count) as $i => $spot) {
            $candidates[] = $this->candidate($context, self::ID_PREFIX.($i + 1), $spot, $radius, $groundArea);
        }

        return new Proposal(
            O::NEWTREE,
            $candidates,
            $this->excluded($ground),
            $this->assumptions($height, $radius),
            [] !== $candidates ? null : (null !== $request->near
                ? sprintf('No free ground within %d m of the point where a tree\'s crown would add shade: built, paved for paths, a pitch or play area, a planned measure or another tree\'s root zone (see excluded_share_of_plot).', (int) self::NEAR_RADIUS_METRES)
                : 'No free ground on this plot where a tree\'s crown would add shade to sunlit open ground: see excluded_share_of_plot for what rules it out. Say so rather than placing one anyway.'),
        );
    }

    public function stillFits(PlacementContext $context, Candidate $candidate): ?string
    {
        $ground = $context->ground;
        $x = (float) $candidate->object[O::X] / self::MILLIMETRES_PER_METRE;
        $z = (float) $candidate->object[O::Z] / self::MILLIMETRES_PER_METRE;
        $cell = $ground->cellAt($x, $z);
        $radius = (float) $candidate->object[O::HEIGHT] * ImpactCalculator::CANOPY_RADIUS_SHARE / self::MILLIMETRES_PER_METRE;

        return null !== $cell && $this->fits($ground, $cell, $radius, $this->crowns($context->objects))
            ? null
            : 'The plan changed since this candidate was proposed: something is planned or stands where the tree would go. Propose again.';
    }

    /**
     * Every tree's trunk and crown radius, metres: existing ones by their recorded crown, else by their height.
     *
     * @param list<array<string, mixed>> $objects
     *
     * @return list<array{x: float, z: float, radius: float, height: float}>
     */
    private function crowns(array $objects): array
    {
        $crowns = [];
        foreach ($objects as $object) {
            if (in_array($object[O::TYPE], [O::TREE, O::NEWTREE], true)) {
                $height = (float) ($object[O::HEIGHT] ?? 0);
                $diameter = (float) ($object[O::DIAMETER] ?? 0);
                $crowns[] = [
                    'x' => (float) $object[O::X] / self::MILLIMETRES_PER_METRE,
                    'z' => (float) $object[O::Z] / self::MILLIMETRES_PER_METRE,
                    'radius' => ($diameter > 0 ? $diameter / 2 : $height * ImpactCalculator::CANOPY_RADIUS_SHARE) / self::MILLIMETRES_PER_METRE,
                    'height' => $height,
                ];
            }
        }

        return $crowns;
    }

    /**
     * @return list<int> cells every SAMPLE_METRES
     */
    private function samples(OpenGround $ground): array
    {
        $step = max(1, (int) round(self::SAMPLE_METRES / OpenGround::CELL_METRES));
        $cells = [];
        for ($r = 0; $r < $ground->rows; $r += $step) {
            for ($c = 0; $c < $ground->columns; $c += $step) {
                $cells[] = $r * $ground->columns + $c;
            }
        }

        return $cells;
    }

    /**
     * Free ground for the pit, the grown crown clear of the walls and of every other crown.
     *
     * @param list<array{x: float, z: float, radius: float, height: float}> $others
     */
    private function fits(OpenGround $ground, int $cell, float $radius, array $others): bool
    {
        if (null !== $ground->blocked[$cell] || $ground->buildingDistance[$cell] < $radius + self::WALL_GAP_METRES) {
            return false;
        }
        [$x, $z] = $ground->centre($cell);
        for ($dx = -self::PIT_RADIUS_METRES; $dx <= self::PIT_RADIUS_METRES; $dx += OpenGround::CELL_METRES) {
            for ($dz = -self::PIT_RADIUS_METRES; $dz <= self::PIT_RADIUS_METRES; $dz += OpenGround::CELL_METRES) {
                $around = $ground->cellAt($x + $dx, $z + $dz);
                if (null === $around || null !== $ground->blocked[$around]) {
                    return false;
                }
            }
        }
        foreach ($others as $other) {
            if (hypot($other['x'] - $x, $other['z'] - $z) < $radius + $other['radius']) {
                return false;
            }
        }

        return true;
    }

    /**
     * m² of open ground the crown's shadow would fall on that no other crown's shadow covers yet:
     * the ranking, before the best are measured with the buildings' shadows too.
     *
     * @param list<array{x: float, z: float, radius: float, height: float}> $others
     */
    private function sunlitUnderShadow(OpenGround $ground, float $x, float $z, float $height, float $radius, array $others): float
    {
        [$sx, $sz] = ImpactCalculator::crownShadowCentre($x * self::MILLIMETRES_PER_METRE, $z * self::MILLIMETRES_PER_METRE, $height);
        $sx /= self::MILLIMETRES_PER_METRE;
        $sz /= self::MILLIMETRES_PER_METRE;
        $shadows = array_map(static function (array $o): array {
            [$ox, $oz] = ImpactCalculator::crownShadowCentre($o['x'] * self::MILLIMETRES_PER_METRE, $o['z'] * self::MILLIMETRES_PER_METRE, $o['height']);

            return [$ox / self::MILLIMETRES_PER_METRE, $oz / self::MILLIMETRES_PER_METRE, $o['radius']];
        }, $others);
        $sunlit = 0.0;
        for ($dx = -$radius; $dx <= $radius; $dx += OpenGround::CELL_METRES) {
            for ($dz = -$radius; $dz <= $radius; $dz += OpenGround::CELL_METRES) {
                if ($dx * $dx + $dz * $dz > $radius * $radius) {
                    continue;
                }
                $cell = $ground->cellAt($sx + $dx, $sz + $dz);
                if (null === $cell || in_array($ground->blocked[$cell], [OpenGround::OUTSIDE, OpenGround::BUILDING], true)) {
                    continue;
                }
                foreach ($shadows as [$ox, $oz, $or]) {
                    if (hypot($sx + $dx - $ox, $sz + $dz - $oz) < $or) {
                        continue 2;
                    }
                }
                $sunlit += OpenGround::CELL_METRES ** 2;
            }
        }

        return $sunlit;
    }

    /**
     * The best ranked positions, each at least $spacing metres from those before it.
     *
     * @param list<array{cell: int, x: float, z: float, score: float}> $ranked
     *
     * @return list<array{cell: int, x: float, z: float, score: float}>
     */
    private static function apart(array $ranked, float $spacing, int $count): array
    {
        $picked = [];
        foreach ($ranked as $spot) {
            foreach ($picked as $other) {
                if (hypot($other['x'] - $spot['x'], $other['z'] - $spot['z']) < $spacing) {
                    continue 2;
                }
            }
            $picked[] = $spot;
            if (count($picked) >= $count) {
                break;
            }
        }

        return $picked;
    }

    /**
     * The tree as it would be planted: with a trench below where the ground soaks water away and is sealed around.
     *
     * @param array{cell: int, x: float, z: float, score: float} $spot
     *
     * @return array<string, mixed>
     */
    private function tree(PlacementContext $context, array $spot, float $height): array
    {
        $trench = $context->infiltrationAt($spot['cell'])->soaksAway() && $this->groundAt($context->objects, $spot['x'], $spot['z'])['class']->isSealed() ? self::TRENCH_M3 : 0;

        return [
            O::TYPE => O::NEWTREE,
            O::X => (int) round($spot['x'] * self::MILLIMETRES_PER_METRE),
            O::Z => (int) round($spot['z'] * self::MILLIMETRES_PER_METRE),
            O::HEIGHT => (int) $height,
            O::TRENCH_VOLUME => $trench,
        ];
    }

    /**
     * The ground at a point as the balance reads it: a described area's surface, else the plot's.
     *
     * @param list<array<string, mixed>> $objects
     *
     * @return array{class: GroundClass, described: bool}
     */
    private function groundAt(array $objects, float $x, float $z): array
    {
        $plotSurface = O::SURFACE_ASPHALT;
        foreach ($objects as $object) {
            if (O::AREA === $object[O::TYPE] && in_array($object[O::SURFACE] ?? null, O::SURFACES, true)) {
                $outline = array_map(static fn (array $p): array => [$p[0] / self::MILLIMETRES_PER_METRE, $p[1] / self::MILLIMETRES_PER_METRE], Site::points($object));
                if (count($outline) >= 3 && Polygon::contains($outline, $x, $z)) {
                    return ['class' => GroundClass::fromSurface((string) $object[O::SURFACE]), 'described' => true];
                }
            }
            if (O::PLOT === $object[O::TYPE] && is_string($object[O::SURFACE] ?? null)) {
                $plotSurface = $object[O::SURFACE];
            }
        }

        return ['class' => GroundClass::fromSurface($plotSurface), 'described' => false];
    }

    /**
     * @param array{cell: int, x: float, z: float, score: float, tree: array<string, mixed>, points: float} $spot
     */
    private function candidate(PlacementContext $context, string $id, array $spot, float $radius, float $groundArea): Candidate
    {
        $tree = $spot['tree'];
        $trench = (int) $tree[O::TRENCH_VOLUME];
        $prices = (new SpongeCityDefinition())->defaultPrices();
        $cost = ((int) $prices->priceOf(Sku::TREE) + $trench * (int) $prices->priceOf(Sku::TREE_TRENCH)) / self::CENTS_PER_EURO;
        $wall = $context->ground->buildingDistance[$spot['cell']];
        $potential = $context->infiltrationAt($spot['cell']);
        $shadedM2 = $spot['points'] / 100 * $groundArea;
        $rain = $context->stormMillimetres / self::MILLIMETRES_PER_METRE;
        $trenchRetained = min($trench * ImpactCalculator::TRENCH_VOID_SHARE, $trench * ImpactCalculator::TREE_TRENCH_CATCHMENT_M2_PER_M3 * GroundClass::Asphalt->runoffCoefficient() * $rain);

        $reasons = [
            sprintf('Its crown (%.0f m across when grown) shades about %.0f m² of open ground that is in the sun at 14:00 on a summer day: +%.2f points of shade, measured in the balance with the buildings\' shadows.', 2 * $radius, $shadedM2, $spot['points']),
            sprintf('Free ground for the planting pit, %.0f m from the nearest wall (the crown needs %.1f m), clear of paths, play areas and the other trees\' crowns.', $wall, $radius + self::WALL_GAP_METRES),
        ];
        if ($trench > 0) {
            $reasons[] = sprintf('With a tree trench of %d m³ below it (Baumrigole): it takes the runoff of about %d m² of sealed ground around it, holds %.1f m³ in the design storm and waters the tree; infiltration is %s here (Versickerungspotentialkarte Hamburg).', $trench, (int) ($trench * ImpactCalculator::TREE_TRENCH_CATCHMENT_M2_PER_M3), $trenchRetained, $potential->value);
        }
        $warnings = ['Pipes and cables below are not in the data: check the utility plans before choosing the spot.'];
        if (0 === $trench && !$potential->soaksAway()) {
            $warnings[] = sprintf('No tree trench: infiltration is %s here, stored water would not soak away.', $potential->value);
        }
        if ($trench > 0 && !$this->groundAt($context->objects, $spot['x'], $spot['z'])['described']) {
            $warnings[] = 'The trench assumes the ground around is sealed (the plot\'s surface): ask the user and record it with describe_zone.';
        }

        return new Candidate(
            $id,
            $tree,
            [
                'x' => $tree[O::X],
                'z' => $tree[O::Z],
                'height_m' => $tree[O::HEIGHT] / self::MILLIMETRES_PER_METRE,
                'crown_diameter_m' => round(2 * $radius, 1),
                'shade_added_points' => round($spot['points'], 2),
                'shade_added_m2' => round($shadedM2),
                'distance_to_building_m' => round($wall, 1),
                'trench_m3' => $trench,
                'trench_retained_m3' => round($trenchRetained, 1),
                'infiltration' => $potential->value,
                'cost_eur' => round($cost),
            ],
            $reasons,
            $warnings,
        );
    }

    /**
     * @return array<string, float> what rules ground out for a tree, as shares of the plot
     */
    private function excluded(OpenGround $ground): array
    {
        $plot = count(array_filter($ground->blocked, static fn (?string $b): bool => OpenGround::OUTSIDE !== $b));
        $counts = array_count_values(array_filter($ground->blocked, static fn (?string $b): bool => null !== $b && OpenGround::OUTSIDE !== $b));
        arsort($counts);

        return $plot > 0 ? array_map(static fn (int $n): float => round($n / $plot, 3), $counts) : [];
    }

    /**
     * @return list<string>
     */
    private function assumptions(float $height, float $radius): array
    {
        return [
            sprintf('A tree of %.0f m when grown with a crown %.0f m across (radius %d %% of its height); change its height with update_object after placing.', $height / self::MILLIMETRES_PER_METRE, 2 * $radius, (int) round(ImpactCalculator::CANOPY_RADIUS_SHARE * 100)),
            sprintf('On free ground only: not on buildings, paths, the plot edge, pitches or playgrounds, fall zones, planned measures or another tree\'s root zone; the crown %.0f m clear of the walls and of the other crowns, a free pit of %.0f m around the trunk.', self::WALL_GAP_METRES, self::PIT_RADIUS_METRES),
            'Ranked by the sunlit open ground the crown\'s shadow falls on at 14:00 on a summer day; the best are measured in the balance (shade share with the tree minus without), buildings\' shadows included.',
            sprintf('A tree trench (Baumrigole, %d m³) where the infiltration map says possible or likely and the ground around is sealed: it holds %d %% of its volume and takes the runoff of %d m² per m³.', self::TRENCH_M3, (int) round(ImpactCalculator::TRENCH_VOID_SHARE * 100), (int) ImpactCalculator::TREE_TRENCH_CATCHMENT_M2_PER_M3),
        ];
    }
}
