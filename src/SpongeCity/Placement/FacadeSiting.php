<?php

declare(strict_types=1);

namespace App\SpongeCity\Placement;

use Core\Configurator\Product\SpongeCity\Impact\CoolingCalculator;
use Core\Configurator\Product\SpongeCity\Impact\ImpactCalculator;
use Core\Configurator\Product\SpongeCity\Impact\Polygon;
use Core\Configurator\Product\SpongeCity\SpongeCityDefinition;
use Core\Configurator\Product\SpongeCity\SpongeCitySku as Sku;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Façade greening on the walls that heat up most: the plot's buildings' walls facing south-east to
 * west, long enough to be worth it, with open ground of the plot at their foot for the climbers'
 * roots. Climbers rooted in the ground, on a trellis, up to the building's eaves or MAX_HEIGHT.
 */
#[AsTaggedItem(index: O::FACADEGREEN)]
final readonly class FacadeSiting implements InterventionSiting
{
    /** a wall shorter than this is not worth a trellis, metres */
    public const float MIN_WALL_METRES = 6.0;
    /** climbers on a trellis reach about this high, millimetres (beyond it a wall-bound system is needed) */
    public const int MAX_HEIGHT = 10000;
    /** the line stands this far out from the wall and stops this far short of the corners, metres */
    private const float OFF_WALL_METRES = 0.2;
    private const float CORNER_METRES = 0.5;
    /** where the roots go: the ground this far out from the wall must be the plot's open ground */
    private const float FOOT_METRES = 1.5;
    /** how much sun a wall gets by the way it faces (azimuth from north, degrees): south and west most */
    private const array SUN_BY_FACING = [[135.0, 165.0, 0.8, 'south-east'], [165.0, 195.0, 1.0, 'south'], [195.0, 245.0, 1.0, 'south-west'], [245.0, 285.0, 0.9, 'west']];
    private const float NEAR_RADIUS_METRES = 30.0;
    private const float MILLIMETRES_PER_METRE = 1000.0;
    private const float CENTS_PER_EURO = 100.0;
    private const string ID_PREFIX = 'facadegreen-';

    public function propose(PlacementContext $context, PlacementRequest $request): Proposal
    {
        $ground = $context->ground;
        $planned = array_map(Site::points(...), $context->ofType(O::FACADEGREEN));
        $walls = [];
        $skipped = ['facing north or east' => 0, 'shorter than '.self::MIN_WALL_METRES.' m' => 0, 'no open ground of the plot at its foot' => 0, 'greened already' => 0];
        foreach ($context->ofType(O::BUILDING) as $building) {
            $footprint = Site::points($building);
            if (count($footprint) < 3) {
                continue;
            }
            $height = min((float) ($building[O::HEIGHT] ?? 0), (float) self::MAX_HEIGHT);
            $count = count($footprint);
            for ($i = 0; $i < $count; ++$i) {
                $wall = $this->wall($footprint, $footprint[$i], $footprint[($i + 1) % $count]);
                if (null === $wall) {
                    continue;
                }
                if ($wall['length'] < self::MIN_WALL_METRES) {
                    ++$skipped['shorter than '.self::MIN_WALL_METRES.' m'];
                    continue;
                }
                $sun = self::sun($wall['azimuth']);
                if (null === $sun) {
                    ++$skipped['facing north or east'];
                    continue;
                }
                [$fx, $fz] = $wall['foot'];
                $cell = $ground->cellAt($fx, $fz);
                if (null === $cell || in_array($ground->blocked[$cell], [OpenGround::OUTSIDE, OpenGround::BUILDING], true)) {
                    ++$skipped['no open ground of the plot at its foot'];
                    continue;
                }
                if (null !== $request->near && hypot($fx - $request->near[0] / self::MILLIMETRES_PER_METRE, $fz - $request->near[1] / self::MILLIMETRES_PER_METRE) > self::NEAR_RADIUS_METRES) {
                    continue;
                }
                if ($this->greened($planned, $wall['line'])) {
                    ++$skipped['greened already'];
                    continue;
                }
                $walls[] = [...$wall, 'building' => $building, 'height' => $height, 'sun' => $sun, 'foot_blocked' => $ground->blocked[$cell]];
            }
        }
        usort($walls, static fn (array $a, array $b): int => $b['sun'][0] * $b['length'] * $b['height'] <=> $a['sun'][0] * $a['length'] * $a['height']);

        $candidates = [];
        foreach (array_slice($walls, 0, $request->count) as $i => $wall) {
            $candidates[] = $this->candidate(self::ID_PREFIX.($i + 1), $wall);
        }
        $total = max(1, array_sum($skipped) + count($walls));

        return new Proposal(
            O::FACADEGREEN,
            $candidates,
            array_filter(array_map(static fn (int $n): float => round($n / $total, 3), $skipped)),
            $this->assumptions(),
            [] !== $candidates ? null : 'No wall of the plot\'s buildings faces south-east to west with open ground of the plot at its foot (excluded_share_of_plot counts the walls by why not). Say so rather than drawing one.',
        );
    }

    public function stillFits(PlacementContext $context, Candidate $candidate): ?string
    {
        $planned = array_map(Site::points(...), $context->ofType(O::FACADEGREEN));

        return $this->greened($planned, array_map(static fn (array $p): array => [$p[0] / self::MILLIMETRES_PER_METRE, $p[1] / self::MILLIMETRES_PER_METRE], $candidate->object[O::POINTS]))
            ? 'This wall is greened already. Propose again.'
            : null;
    }

    /**
     * One wall of a footprint: its length (metres), which way it faces, the line the climbers stand on
     * (metres, off the wall, short of the corners) and a point at its foot.
     *
     * @param list<array{0: float, 1: float}> $footprint millimetres
     * @param array{0: float, 1: float}       $a
     * @param array{0: float, 1: float}       $b
     *
     * @return array{length: float, azimuth: float, line: list<array{0: float, 1: float}>, foot: array{0: float, 1: float}}|null
     */
    private function wall(array $footprint, array $a, array $b): ?array
    {
        $ax = $a[0] / self::MILLIMETRES_PER_METRE;
        $az = $a[1] / self::MILLIMETRES_PER_METRE;
        $bx = $b[0] / self::MILLIMETRES_PER_METRE;
        $bz = $b[1] / self::MILLIMETRES_PER_METRE;
        $length = hypot($bx - $ax, $bz - $az);
        if ($length <= 2 * self::CORNER_METRES) {
            return null;
        }
        $ux = ($bx - $ax) / $length;
        $uz = ($bz - $az) / $length;
        // the normal pointing away from the building
        [$nx, $nz] = [$uz, -$ux];
        $mx = ($ax + $bx) / 2;
        $mz = ($az + $bz) / 2;
        if (Polygon::contains($footprint, ($mx + $nx * self::FOOT_METRES) * self::MILLIMETRES_PER_METRE, ($mz + $nz * self::FOOT_METRES) * self::MILLIMETRES_PER_METRE)) {
            [$nx, $nz] = [-$nx, -$nz];
        }
        // x east, z south: north is -z, the azimuth runs clockwise from it
        $azimuth = fmod(rad2deg(atan2($nx, -$nz)) + 360.0, 360.0);
        $off = static fn (float $x, float $z): array => [$x + $nx * self::OFF_WALL_METRES, $z + $nz * self::OFF_WALL_METRES];

        return [
            'length' => $length - 2 * self::CORNER_METRES,
            'azimuth' => $azimuth,
            'line' => [$off($ax + $ux * self::CORNER_METRES, $az + $uz * self::CORNER_METRES), $off($bx - $ux * self::CORNER_METRES, $bz - $uz * self::CORNER_METRES)],
            'foot' => [$mx + $nx * self::FOOT_METRES, $mz + $nz * self::FOOT_METRES],
        ];
    }

    /**
     * @return array{0: float, 1: string}|null the sun factor and the name of the facing, null when it faces north or east
     */
    private static function sun(float $azimuth): ?array
    {
        foreach (self::SUN_BY_FACING as [$from, $to, $factor, $name]) {
            if ($azimuth >= $from && $azimuth < $to) {
                return [$factor, $name];
            }
        }

        return null;
    }

    /**
     * @param list<list<array{0: float, 1: float}>> $planned the planned greenings' lines, millimetres
     * @param list<array{0: float, 1: float}>       $line    metres
     */
    private function greened(array $planned, array $line): bool
    {
        $mx = ($line[0][0] + $line[1][0]) / 2 * self::MILLIMETRES_PER_METRE;
        $mz = ($line[0][1] + $line[1][1]) / 2 * self::MILLIMETRES_PER_METRE;
        foreach ($planned as $points) {
            for ($i = 1, $n = count($points); $i < $n; ++$i) {
                // the middle of the wall lies on a planned line
                if (Polygon::segmentDistance($mx, $mz, $points[$i - 1], $points[$i]) < self::FOOT_METRES * self::MILLIMETRES_PER_METRE) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array{length: float, azimuth: float, line: list<array{0: float, 1: float}>, foot: array{0: float, 1: float}, building: array<string, mixed>, height: float, sun: array{0: float, 1: string}, foot_blocked: string|null} $wall
     */
    private function candidate(string $id, array $wall): Candidate
    {
        $points = array_map(static fn (array $p): array => [(int) round($p[0] * self::MILLIMETRES_PER_METRE), (int) round($p[1] * self::MILLIMETRES_PER_METRE)], $wall['line']);
        $height = (int) (floor($wall['height'] / 500) * 500);
        $object = [O::TYPE => O::FACADEGREEN, O::POINTS => $points, O::HEIGHT => $height, O::LABEL => SiteType::shortLabel(sprintf('Façade greening: %s, %s wall', Site::label($wall['building']) ?? 'building', $wall['sun'][1]))];
        $area = ImpactCalculator::wallArea($object);
        $litres = $area * CoolingCalculator::REFERENCE_EVAPOTRANSPIRATION_MM * CoolingCalculator::FACADE_CROP_COEFFICIENT;
        $cost = $area * (int) (new SpongeCityDefinition())->defaultPrices()->priceOf(Sku::FACADE_GREEN) / self::CENTS_PER_EURO;

        $reasons = [
            sprintf('A %s-facing wall of %s, %.0f m long: it gets the afternoon sun and heats up most.', $wall['sun'][1], Site::label($wall['building']) ?? 'the building', $wall['length']),
            sprintf('Climbers rooted at its foot on a trellis, %.1f m high: %.0f m² of wall, about %d K cooler behind the leaves at midday, evaporating about %.0f litres on a hot day.', $height / self::MILLIMETRES_PER_METRE, $area, (int) CoolingCalculator::FACADE_WALL_COOLING_K, $litres),
        ];
        $warnings = ['It cools the wall and the air close to it, not the open ground: calculate_cooling shows its evaporation, calculate_impact does not change.'];
        if (OpenGround::PATH === $wall['foot_blocked']) {
            $warnings[] = 'A path runs along the wall: plant in a strip cut into it or in large planters.';
        }
        if ($wall['height'] >= self::MAX_HEIGHT) {
            $warnings[] = sprintf('The wall is higher than climbers reach on a trellis (%.0f m): above it a wall-bound system would be needed.', self::MAX_HEIGHT / self::MILLIMETRES_PER_METRE);
        }
        $warnings[] = 'Check the wall first: windows, downpipes and the façade\'s insulation decide the trellis and the plants.';

        return new Candidate(
            $id,
            $object,
            [
                'building' => $wall['building'][O::ID] ?? null,
                'facing' => $wall['sun'][1],
                'length_m' => round($wall['length'], 1),
                'height_m' => $height / self::MILLIMETRES_PER_METRE,
                'area_m2' => round($area),
                'evapotranspiration_l_per_day' => round($litres),
                'wall_cooler_k' => CoolingCalculator::FACADE_WALL_COOLING_K,
                'cost_eur' => round($cost),
            ],
            $reasons,
            $warnings,
        );
    }

    /**
     * @return list<string>
     */
    private function assumptions(): array
    {
        return [
            sprintf('Walls of the plot\'s buildings facing south-east to west (they get the afternoon sun), at least %.0f m long, with open ground of the plot %.1f m out from them for the roots; the sunniest and largest first.', self::MIN_WALL_METRES, self::FOOT_METRES),
            sprintf('Climbers on a trellis up to the eaves or %.0f m, %.1f m short of the corners.', self::MAX_HEIGHT / self::MILLIMETRES_PER_METRE, self::CORNER_METRES),
            sprintf('Evaporation %.1f x ET0 per m² of wall on a hot day; the wall about %d K cooler at midday (measurements: 5 to 15 K).', CoolingCalculator::FACADE_CROP_COEFFICIENT, (int) CoolingCalculator::FACADE_WALL_COOLING_K),
        ];
    }
}
