<?php

declare(strict_types=1);

namespace Core\Configurator\Site;

use Core\Configurator\Engine\Configuration;
use Core\Configurator\Site\SiteObject as O;

/**
 * A configuration's surroundings (SiteType's canonical value): read by the
 * product's staging, which leaves out its own house when the site has one and
 * keeps its plants and furniture off what the site covers.
 */
final readonly class Site
{
    /** a tree's trunk and the space right around it */
    private const int TREE_RADIUS = 800;
    private const int CIRCLE_SEGMENTS = 16;

    /**
     * @param list<array<string, mixed>> $objects normalised objects
     */
    public function __construct(public array $objects = [])
    {
    }

    /** The site of a configuration; empty for a product without one. */
    public static function of(Configuration $configuration): self
    {
        return $configuration->has(SiteType::PARAMETER) ? self::fromJson($configuration->string(SiteType::PARAMETER)) : new self();
    }

    public static function fromJson(string $json): self
    {
        $objects = [];
        foreach (SiteType::rawObjects($json) as $object) {
            if (is_array($object) && is_string($object[O::TYPE] ?? null)) {
                /* @var array<string, mixed> $object */
                $objects[] = $object;
            }
        }

        return new self($objects);
    }

    public function isEmpty(): bool
    {
        return [] === $this->objects;
    }

    public function hasHouse(): bool
    {
        return [] !== $this->ofType(O::HOUSE);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function ofType(string $type): array
    {
        return array_values(array_filter($this->objects, static fn (array $object): bool => $type === $object[O::TYPE]));
    }

    /**
     * @return array<string, mixed>|null the plot the site is planned on, if it has one
     */
    public function plot(): ?array
    {
        return $this->ofType(O::PLOT)[0] ?? null;
    }

    /**
     * @return list<array{0: int|float, 1: int|float}> the plot's outline; empty without a plot
     */
    public function plotOutline(): array
    {
        $plot = $this->plot();

        return null === $plot ? [] : self::points($plot);
    }

    /**
     * What each object covers in plan, as polygons [x, z]: its footprint,
     * a band along a hedge or wall, the ground around a tree's trunk.
     *
     * @return list<list<array{float, float}>>
     */
    public function outlines(): array
    {
        $outlines = [];
        foreach ($this->objects as $object) {
            array_push($outlines, ...self::outlinesOf($object));
        }

        return $outlines;
    }

    /** Whether the point lies on something of the site, or within $margin of it. */
    public function covers(float $x, float $z, float $margin = 0.0): bool
    {
        foreach ($this->outlines() as $outline) {
            if (self::inside($outline, $x, $z) || self::distanceTo($outline, $x, $z) < $margin) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $object
     *
     * @return list<list<array{float, float}>>
     */
    public static function outlinesOf(array $object): array
    {
        $num = static fn (string $key): float => is_numeric($object[$key] ?? null) ? (float) $object[$key] : 0.0;
        $points = self::points($object);

        return match ($object[O::TYPE]) {
            O::HOUSE, O::OUTBUILDING => [self::rectangle($num(O::X), $num(O::Z), $num(O::TURN), $num(O::WIDTH), $num(O::DEPTH), true)],
            O::POOL, O::BOX, O::EQUIPMENT => [self::rectangle($num(O::X), $num(O::Z), $num(O::TURN), $num(O::WIDTH), $num(O::DEPTH), false)],
            O::CYLINDER => [self::circle($num(O::X), $num(O::Z), $num(O::DIAMETER) / 2)],
            O::TREE, O::NEWTREE => [self::circle($num(O::X), $num(O::Z), self::TREE_RADIUS)],
            O::HEDGE, O::WALL => self::bands($points, $num(O::THICKNESS)),
            O::ROAD => self::bands($points, $num(O::WIDTH)),
            default => [$points],
        };
    }

    /**
     * A building's own roof from its 3D model (SiteObject::MODEL), null when it has none.
     *
     * @param array<string, mixed> $object
     *
     * @return array{roofs: list<list<array{float, float, float}>>, walls: list<list<array{float, float, float}>>}|null
     */
    public static function model(array $object): ?array
    {
        $model = $object[O::MODEL] ?? null;
        if (!is_array($model) || !is_array($model[O::MODEL_ROOFS] ?? null) || [] === $model[O::MODEL_ROOFS]) {
            return null;
        }
        $polygons = static fn (mixed $list): array => array_values(array_map(
            static fn (array $polygon): array => array_map(static fn (array $c): array => [(float) $c[0], (float) $c[1], (float) $c[2]], $polygon),
            array_filter(is_array($list) ? $list : [], 'is_array'),
        ));

        return [O::MODEL_ROOFS => $polygons($model[O::MODEL_ROOFS]), O::MODEL_WALLS => $polygons($model[O::MODEL_WALLS] ?? [])];
    }

    /**
     * A building's roof levels (SiteObject::PARTS): outlines in plan millimetres and heights.
     *
     * @param array<string, mixed> $object
     *
     * @return list<array{points: list<array{float, float}>, height: float}>
     */
    public static function parts(array $object): array
    {
        $parts = [];
        foreach (is_array($object[O::PARTS] ?? null) ? $object[O::PARTS] : [] as $part) {
            $points = is_array($part) ? self::points($part) : [];
            if (count($points) >= 3 && is_numeric($part[O::HEIGHT] ?? null)) {
                $parts[] = [O::POINTS => $points, O::HEIGHT => (float) $part[O::HEIGHT]];
            }
        }

        return $parts;
    }

    /**
     * @param array<string, mixed> $object
     */
    public static function label(array $object): ?string
    {
        return is_string($object[O::LABEL] ?? null) ? $object[O::LABEL] : null;
    }

    /**
     * @param array<string, mixed> $object
     *
     * @return list<array{float, float}>
     */
    public static function points(array $object): array
    {
        $points = [];
        foreach (is_array($object[O::POINTS] ?? null) ? $object[O::POINTS] : [] as $point) {
            if (is_array($point) && is_numeric($point[0] ?? null) && is_numeric($point[1] ?? null)) {
                $points[] = [(float) $point[0], (float) $point[1]];
            }
        }

        return $points;
    }

    /**
     * A rectangle turned about x/z: centred on it, or (a building) with its
     * front edge's middle there and the rest behind.
     *
     * @return list<array{float, float}>
     */
    private static function rectangle(float $x, float $z, float $turn, float $width, float $depth, bool $fromFront): array
    {
        $angle = deg2rad($turn);
        [$front, $back] = $fromFront ? [0.0, -$depth] : [$depth / 2, -$depth / 2];

        return array_map(
            static fn (array $p): array => [$x + $p[0] * cos($angle) + $p[1] * sin($angle), $z - $p[0] * sin($angle) + $p[1] * cos($angle)],
            [[-$width / 2, $front], [$width / 2, $front], [$width / 2, $back], [-$width / 2, $back]],
        );
    }

    /**
     * @return list<array{float, float}>
     */
    private static function circle(float $x, float $z, float $radius): array
    {
        return array_map(
            static fn (int $i): array => [$x + $radius * cos(2 * M_PI * $i / self::CIRCLE_SEGMENTS), $z + $radius * sin(2 * M_PI * $i / self::CIRCLE_SEGMENTS)],
            range(0, self::CIRCLE_SEGMENTS - 1),
        );
    }

    /**
     * @param list<array{float, float}> $points
     *
     * @return list<list<array{float, float}>>
     */
    private static function bands(array $points, float $thickness): array
    {
        $bands = [];
        for ($i = 1, $count = count($points); $i < $count; ++$i) {
            [$ax, $az] = $points[$i - 1];
            [$bx, $bz] = $points[$i];
            $length = hypot($bx - $ax, $bz - $az);
            if ($length < 1) {
                continue;
            }
            // half the thickness out to each side
            $nx = -($bz - $az) / $length * $thickness / 2;
            $nz = ($bx - $ax) / $length * $thickness / 2;
            $bands[] = [[$ax + $nx, $az + $nz], [$bx + $nx, $bz + $nz], [$bx - $nx, $bz - $nz], [$ax - $nx, $az - $nz]];
        }

        return $bands;
    }

    /**
     * @param list<array{float, float}> $polygon
     */
    private static function inside(array $polygon, float $x, float $z): bool
    {
        $inside = false;
        $count = count($polygon);
        foreach ($polygon as $i => [$ax, $az]) {
            [$bx, $bz] = $polygon[($i + 1) % $count];
            if (($az > $z) !== ($bz > $z) && $x < $ax + ($bx - $ax) * ($z - $az) / ($bz - $az)) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    /**
     * @param list<array{float, float}> $polygon
     */
    private static function distanceTo(array $polygon, float $x, float $z): float
    {
        $nearest = INF;
        $count = count($polygon);
        foreach ($polygon as $i => [$ax, $az]) {
            [$bx, $bz] = $polygon[($i + 1) % $count];
            $dx = $bx - $ax;
            $dz = $bz - $az;
            $lengthSquared = $dx * $dx + $dz * $dz;
            $t = $lengthSquared > 0 ? max(0, min(1, (($x - $ax) * $dx + ($z - $az) * $dz) / $lengthSquared)) : 0;
            $nearest = min($nearest, hypot($x - ($ax + $t * $dx), $z - ($az + $t * $dz)));
        }

        return $nearest;
    }
}
