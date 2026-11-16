<?php

declare(strict_types=1);

namespace Core\Configurator\Site;

use Core\Configurator\Assembly\Assembly;
use Core\Configurator\Assembly\Box;
use Core\Configurator\Assembly\Model;
use Core\Configurator\Assembly\Part;
use Core\Configurator\Assembly\Vector3;
use Core\Configurator\Scene\GardenModels;
use Core\Configurator\Scene\Material;
use Core\Configurator\Scene\ProceduralTexture;
use Core\Configurator\Scenery\Building;
use Core\Configurator\Scenery\House;
use Core\Configurator\Scenery\Slab;
use Core\Configurator\Site\SiteObject as O;

/**
 * The site as scenery parts (no SKU), in plan coordinates. Colours the
 * customer chose become materials of the assembly (Assembly::material()).
 * Evaluation::scene() adds it to every product that has a site.
 */
final class SiteBuilder
{
    public const string LABEL_PREFIX = 'site.part.';
    private const string MATERIAL_PREFIX = 'site_';
    private const string HEDGE = 'site_hedge';
    private const string WATER = 'site_water';
    private const string COPING = 'site_coping';
    private const string DOOR = 'site_door';
    private const int PAVING = 40;
    /** paving stands this far above the lawn */
    private const int SURFACE = 10;
    private const int COPING_WIDTH = 300;
    private const int CIRCLE_SEGMENTS = 20;
    private const string TREE_VARIANT = 'tree_01';
    /** the roof slab on a footprint: thin, it only carries the roof's colour */
    private const int FOOTPRINT_ROOF = 80;
    private const float TILE_TEXTURE_SIZE = 1.2;
    /** pitched roofs on footprints (at the building's own pitch, else PitchedRoof::DEFAULT_PITCH); a roof surface of a building's model below this slope is flat: gravel, not tiles */
    private const float FLAT_ROOF_DEGREES = 10.0;
    private const int EAVES_OVERHANG = 400;
    private const int ROOF_THICKNESS = 160;
    private const int GABLE_THICKNESS = 250;
    private const float PLASTER_TEXTURE_SIZE = 1.6;
    private const float GRAVEL_TEXTURE_SIZE = 4.0;
    /**
     * roads: thin strips above the plot (4 mm), footways above carriageways, 3 mm apart: a
     * smaller step stops being told apart from far away and the layers z-fight in stripes
     */
    private const int ROAD_THICKNESS = 30;
    private const int CARRIAGEWAY_TOP = 7;
    private const int FOOTWAY_TOP = 10;
    private const string ASPHALT_COLOUR = '#5a5b5d';
    private const string FOOTWAY_COLOUR = '#b3aea5';
    private const float ASPHALT_TEXTURE_SIZE = 6.0;
    private const float FOOTWAY_TEXTURE_SIZE = 0.6;

    private const float MILLIMETRES_PER_METRE = 1000.0;
    /** roof parts narrower than this (millimetres, mean width) are wall tops: a glazed band or a railing */
    private const float GLAZED_BAND_WIDTH = 1000.0;
    private const float GLAZING_REPEAT_WIDTH = 6.0;
    /** a ground floor is a quarter taller than the storeys above, 3.5 to 5.5 m (buildings of three storeys and more) */
    private const float GROUND_FLOOR_SHARE = 1.25;
    private const float GROUND_FLOOR_MIN = 3.5;
    private const float GROUND_FLOOR_MAX = 5.5;
    private const int GROUND_FLOOR_MIN_STOREYS = 3;
    private const float GLAZING_REPEAT_HEIGHT = 3.2;

    private Assembly $assembly;

    public function build(Site $site): Assembly
    {
        $this->assembly = new Assembly();
        foreach ($site->objects as $i => $object) {
            $type = is_string($object[O::TYPE] ?? null) ? $object[O::TYPE] : '';
            $label = is_string($object[O::LABEL] ?? null) ? $object[O::LABEL] : self::LABEL_PREFIX.$type;
            // upright objects stand on their ground; ground layers are draped over the terrain by the view
            $upright = in_array($type, O::UPRIGHT, true);
            $ground = $upright && is_numeric($object[O::GROUND] ?? null) ? (float) $object[O::GROUND] : 0.0;
            foreach ($this->parts($object, $label, $i) as $part) {
                $this->assembly->add($upright ? $part->raisedBy($ground) : $part->drapedOverTerrain());
            }
            foreach (Site::outlinesOf($object) as $outline) {
                $xs = array_column($outline, 0);
                $zs = array_column($outline, 1);
                if ([] !== $xs && [] !== $zs) {
                    $this->assembly->keepClear(min($xs), max($xs), min($zs), max($zs));
                }
            }
        }

        return $this->assembly;
    }

    /**
     * @param array<string, mixed> $object
     *
     * @return list<Part>
     */
    private function parts(array $object, string $label, int $index): array
    {
        $num = static fn (string $key): float => is_numeric($object[$key] ?? null) ? (float) $object[$key] : 0.0;
        $text = static fn (string $key): string => is_string($object[$key] ?? null) ? $object[$key] : '';
        $points = Site::points($object);

        return match ($object[O::TYPE]) {
            O::HOUSE => $this->building($label, $object, $num(O::STOREYS) * Building::STOREY + 400, (int) $num(O::STOREYS), Building::DOOR_ENTRANCE),
            O::OUTBUILDING => $this->building($label, $object, $num(O::HEIGHT), 0, $text(O::DOOR)),
            O::HEDGE => $this->along($label, $points, $num(O::HEIGHT), $num(O::THICKNESS), $this->hedge()),
            O::WALL => $this->along($label, $points, $num(O::HEIGHT), $num(O::THICKNESS), $this->colour('wall', $text(O::COLOR), ProceduralTexture::Plaster)),
            O::TREE => [new Part(null, $label, Model::ofHeight(GardenModels::TREES, self::TREE_VARIANT, $num(O::HEIGHT)), position: new Vector3($num(O::X), 0, $num(O::Z)), rotation: new Vector3(0, $index * 2.4, 0))],
            O::PAVING => [Slab::onGround($label, $this->colour('paving', $text(O::COLOR), ProceduralTexture::Paving), $points, self::PAVING, self::SURFACE - self::PAVING)],
            O::POOL => $this->pool($label, $num(O::X), $num(O::Z), deg2rad($num(O::TURN)), $num(O::WIDTH), $num(O::DEPTH)),
            O::BOX => [(new Part(null, $label, new Box($num(O::WIDTH), $num(O::HEIGHT), $num(O::DEPTH)), $this->colour('solid', $text(O::COLOR)), new Vector3(0, $num(O::Y) + $num(O::HEIGHT) / 2, 0)))
                ->placedAt($num(O::X), $num(O::Z), deg2rad($num(O::TURN)))],
            O::CYLINDER => [Slab::onGround($label, $this->colour('solid', $text(O::COLOR)), self::circle($num(O::X), $num(O::Z), $num(O::DIAMETER) / 2), $num(O::HEIGHT), $num(O::Y))],
            O::PRISM => [Slab::onGround($label, $this->colour('solid', $text(O::COLOR)), $points, $num(O::HEIGHT), $num(O::Y))],
            O::ROAD => $this->road($label, $points, $num(O::WIDTH), O::ROAD_FOOTWAY === $text(O::KIND)),
            O::BUILDING => $this->footprint($label, $points, $num(O::HEIGHT), Facade::tryFrom($text(O::FACADE)) ?? Facade::Plaster, $text(O::COLOR), $text(O::ROOF), $text(O::ROOF_COLOR), (int) $num(O::STOREYS), Site::parts($object), $num(O::PITCH), Site::model($object)),
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $object
     *
     * @return list<Part>
     */
    private function building(string $label, array $object, float $eaves, int $storeys, string $door): array
    {
        $num = static fn (string $key): float => is_numeric($object[$key] ?? null) ? (float) $object[$key] : 0.0;
        $text = static fn (string $key): string => is_string($object[$key] ?? null) ? $object[$key] : '';
        $roof = $text(O::ROOF);
        $facade = $text(O::DOOR_FACADE);
        if (O::HOUSE === $object[O::TYPE] && Building::DOOR_NONE === $facade) {
            $door = Building::DOOR_NONE;
        }
        $this->houseMaterials();
        $building = new Building(
            $label,
            $num(O::WIDTH),
            $num(O::DEPTH),
            $eaves,
            in_array($roof, Building::ROOFS, true) ? $roof : Building::ROOF_FLAT,
            $num(O::PITCH),
            O::RIDGE_ACROSS !== $text(O::RIDGE),
            $this->colour('wall', $text(O::WALL_COLOR), ProceduralTexture::Plaster),
            $this->colour(Building::ROOF_FLAT === $roof ? 'flat' : 'roof', $text(O::ROOF_COLOR), Building::ROOF_FLAT === $roof ? null : ProceduralTexture::RoofTiles),
            House::MATERIAL_FRAME,
            House::MATERIAL_GLASS,
            $this->door(),
            $storeys,
            $door,
            in_array($facade, Building::FACADES, true) ? $facade : Building::FACADE_FRONT,
            $num(O::DOOR_OFFSET),
        );

        return array_map(static fn (Part $part): Part => $part->placedAt($num(O::X), $num(O::Z), deg2rad($num(O::TURN))), $building->parts());
    }

    /**
     * A building footprint: walls with the façade's windows up to its height, and a
     * thin roof slab on top in the roof's colour (tiles on a pitched roof, gravel on a
     * flat one; a footprint has no roof shape of its own).
     *
     * A pitched roof is drawn over the outline's rectangle at its pitch (PitchedRoof), or, where
     * the building has its own model (an L, a half-hip, a mix of roofs), as the model's roof
     * surfaces and its walls above the eaves.
     *
     * @param list<array{float, float}>                                                          $points
     * @param array{roofs: list<list<array{float, float, float}>>, walls: list<list<array{float, float, float}>>}|null $model
     *
     * @return list<Part>
     */
    private function footprint(string $label, array $points, float $height, Facade $facade, string $colour, string $roof, string $roofColour, int $storeys = 0, array $parts = [], float $pitch = 0.0, ?array $model = null): array
    {
        $flat = Building::ROOF_FLAT === $roof;
        // with roof levels the cadastre's storeys belong to the main one (the largest, first)
        $main = $parts[0][O::HEIGHT] ?? $height;
        $walls = $this->facadeWalls($facade, $colour, $main, $storeys);
        $roofMaterial = $flat
            ? $this->material('roof_flat', $roofColour, ProceduralTexture::RoofGravel, self::GRAVEL_TEXTURE_SIZE)
            : $this->material('roof_tiles', $roofColour, ProceduralTexture::RoofTiles, self::TILE_TEXTURE_SIZE);

        if ($flat && [] !== $parts) {
            // the roof levels of the 3D model: a block each, side by side, each with its roof
            $blocks = [];
            $glass = self::MATERIAL_PREFIX.'glazing';
            // one repeat: four 1.5 m panels across, the band's usual storey up
            $this->assembly->material($glass, new Material('#ffffff', roughness: 0.12, metalness: 0.35, texture: ProceduralTexture::Glazing, textureSize: self::GLAZING_REPEAT_WIDTH, textureHeight: self::GLAZING_REPEAT_HEIGHT));
            foreach ($parts as $part) {
                // a thin band rising above the main roof is the glazed front of a top storey or a glass railing
                // (the model draws it as a wall top under a metre wide): rendered wall up to the main roof, glass above
                if (self::meanWidth($part[O::POINTS]) < self::GLAZED_BAND_WIDTH && $part[O::HEIGHT] > $main) {
                    array_push($blocks, ...$walls($part[O::POINTS], $main));
                    $blocks[] = Slab::onGround($label, $glass, $part[O::POINTS], $part[O::HEIGHT] - $main, $main);
                } else {
                    array_push($blocks, ...$walls($part[O::POINTS], $part[O::HEIGHT]));
                }
                $blocks[] = Slab::onGround($label, $roofMaterial, $part[O::POINTS], self::FOOTPRINT_ROOF, $part[O::HEIGHT]);
            }

            return array_map(static fn (Part $p): Part => new Part($p->sku, $label, $p->shape, $p->material, $p->position, $p->rotation), $blocks);
        }

        $wallParts = array_map(static fn (Part $p): Part => new Part($p->sku, $label, $p->shape, $p->material, $p->position, $p->rotation), $walls($points, $height));
        if (!$flat && null !== $model) {
            return [...$wallParts, ...$this->modelRoof($label, $model, $colour, $roofColour, $roofMaterial)];
        }
        $rectangle = $flat ? null : PitchedRoof::rectangleAround($points);
        if (null !== $rectangle) {
            [$gableMaterial] = array_map(static fn (Part $p): ?string => $p->material, array_slice($wallParts, -1));

            return [...$wallParts, ...$this->pitchedRoof($label, $rectangle, $height, $roof, $roofMaterial, (string) $gableMaterial, $pitch > 0 ? $pitch : PitchedRoof::DEFAULT_PITCH)];
        }

        return [...$wallParts, Slab::onGround($label, $roofMaterial, $points, self::FOOTPRINT_ROOF, $height)];
    }

    /**
     * The façade's walls for an outline up to a height: one row of windows per storey, the ground
     * floor taller than the rest (shops, halls, entrances) when the storeys are known, as two pieces
     * stacked so each has its own rows. Returns a function of (outline, top) giving the wall parts.
     *
     * @return callable(list<array{float, float}>, float): list<Part>
     */
    private function facadeWalls(Facade $facade, string $colour, float $mainHeight, int $storeys): callable
    {
        $material = function (?float $textureHeight) use ($facade, $colour): string {
            $key = self::MATERIAL_PREFIX.'facade_'.$facade->value.'_'.ltrim($colour, '#').(null === $textureHeight ? '' : '_'.(int) round($textureHeight * 100));
            $this->assembly->material($key, new Material(
                $colour,
                roughness: 0.85,
                texture: $facade->texture(),
                textureSize: $facade->textureSize(),
                nightGlow: $facade->hasWindows() ? Facade::NIGHT_GLOW : null,
                nightGlowIntensity: $facade->hasWindows() ? Facade::NIGHT_GLOW_INTENSITY : 0.0,
                textureHeight: $textureHeight,
            ));

            return $key;
        };
        $metres = $mainHeight / self::MILLIMETRES_PER_METRE;
        $storey = $storeys > 0 ? $metres / $storeys : 0.0;
        if ($storeys < self::GROUND_FLOOR_MIN_STOREYS || !$facade->hasWindows()) {
            $uniform = $material($facade->textureHeight($metres, $storeys));

            return static fn (array $points, float $top): array => [Slab::onGround('', $uniform, $points, $top)];
        }
        $ground = min(self::GROUND_FLOOR_MAX, max(self::GROUND_FLOOR_MIN, $storey * self::GROUND_FLOOR_SHARE));
        $groundWalls = $material($facade->textureHeight($ground, 1));
        $upperWalls = $material($facade->textureHeight($metres - $ground, $storeys - 1));
        $groundMillimetres = $ground * self::MILLIMETRES_PER_METRE;

        return static fn (array $points, float $top): array => $top <= $groundMillimetres
            ? [Slab::onGround('', $groundWalls, $points, $top)]
            : [Slab::onGround('', $groundWalls, $points, $groundMillimetres), Slab::onGround('', $upperWalls, $points, $top - $groundMillimetres, $groundMillimetres)];
    }

    /**
     * A gable or hip roof over a footprint's rectangle: the ridge along its long side,
     * the eaves overhanging, gable walls in the façade's material. A monopitch roof is
     * built as a gable (from above they look alike).
     *
     * @param array{float, float, float, float, float, float} $rectangle centre x/z, long direction x/z, half length, half width
     *
     * @return list<Part>
     */
    private function pitchedRoof(string $label, array $rectangle, float $eaves, string $roof, string $roofMaterial, string $wallMaterial, float $pitch): array
    {
        [$cx, $cz, $dx, $dz, $a, $b] = $rectangle;
        $tan = tan(deg2rad($pitch));
        $o = self::EAVES_OVERHANG;
        $top = $eaves + $b * $tan;
        $low = $eaves - $o * $tan;
        // (along the ridge, across it, height) in the rectangle's frame → plan
        $at = static fn (float $u, float $v, float $y): array => [$cx + $u * $dx - $v * $dz, $y, $cz + $u * $dz + $v * $dx];
        $planes = [];
        if (Building::ROOF_HIP === $roof) {
            $r = max(0.0, $a - $b);
            foreach ([1, -1] as $side) {
                $planes[] = [$at(-$side * ($a + $o), $side * ($b + $o), $low), $at($side * ($a + $o), $side * ($b + $o), $low), $at($side * $r, 0, $top), $at(-$side * $r, 0, $top)];
                $planes[] = [$at($side * ($a + $o), $side * ($b + $o), $low), $at($side * ($a + $o), -$side * ($b + $o), $low), $at($side * $r, 0, $top)];
            }
            $gables = [];
        } else {
            foreach ([1, -1] as $side) {
                $planes[] = [$at(-$a - $o, $side * ($b + $o), $low), $at($a + $o, $side * ($b + $o), $low), $at($a + $o, 0, $top), $at(-$a - $o, 0, $top)];
            }
            $gables = array_map(static fn (int $end): array => [$at($end * $a, $b, $eaves), $at($end * $a, -$b, $eaves), $at($end * $a, 0, $top)], [1, -1]);
        }

        return array_values(array_filter([
            ...array_map(static fn (array $plane): ?Part => Slab::planar($label, $roofMaterial, $plane, self::ROOF_THICKNESS), $planes),
            ...array_map(static fn (array $gable): ?Part => Slab::planar($label, $wallMaterial, $gable, self::GABLE_THICKNESS, centred: true), $gables),
        ]));
    }

    /**
     * A building's own roof from its 3D model: each roof surface a slab in its plane (gravel where
     * it is flat, tiles where it slopes), each wall above the eaves (gables, the walls between roof
     * levels) a plain wall in the façade's colour.
     *
     * @param array{roofs: list<list<array{float, float, float}>>, walls: list<list<array{float, float, float}>>} $model
     *
     * @return list<Part>
     */
    private function modelRoof(string $label, array $model, string $colour, string $roofColour, string $roofMaterial): array
    {
        $flatMaterial = $this->material('roof_flat', $roofColour, ProceduralTexture::RoofGravel, self::GRAVEL_TEXTURE_SIZE);
        $wallMaterial = $this->colour('gable', $colour, ProceduralTexture::Plaster);
        $parts = [];
        foreach ($model[O::MODEL_ROOFS] as $polygon) {
            $polygon = self::fromLevelEdge($polygon);
            $material = self::slope($polygon) < self::FLAT_ROOF_DEGREES ? $flatMaterial : $roofMaterial;
            $parts[] = Slab::planar($label, $material, $polygon, self::ROOF_THICKNESS);
        }
        foreach ($model[O::MODEL_WALLS] as $polygon) {
            $parts[] = Slab::planar($label, $wallMaterial, $polygon, self::GABLE_THICKNESS, centred: true);
        }

        return array_values(array_filter($parts));
    }

    /**
     * A polygon starting at its longest level edge, so a slab's texture (laid along its first edge)
     * runs along the eaves: rows of tiles horizontal.
     *
     * @param list<array{float, float, float}> $polygon
     *
     * @return list<array{float, float, float}>
     */
    private static function fromLevelEdge(array $polygon): array
    {
        $best = 0;
        $bestScore = -1.0;
        for ($i = 0, $n = count($polygon); $i < $n; ++$i) {
            [$a, $b] = [$polygon[$i], $polygon[($i + 1) % $n]];
            $length = sqrt(($b[0] - $a[0]) ** 2 + ($b[1] - $a[1]) ** 2 + ($b[2] - $a[2]) ** 2);
            $score = $length > 0 ? $length * (1 - abs($b[1] - $a[1]) / $length) ** 4 : 0.0;
            if ($score > $bestScore) {
                [$best, $bestScore] = [$i, $score];
            }
        }

        return [...array_slice($polygon, $best), ...array_slice($polygon, 0, $best)];
    }

    /**
     * How steep a planar polygon is, in degrees (Newell's normal).
     *
     * @param list<array{float, float, float}> $polygon
     */
    private static function slope(array $polygon): float
    {
        $n = [0.0, 0.0, 0.0];
        for ($i = 0, $count = count($polygon); $i < $count; ++$i) {
            [$a, $b] = [$polygon[$i], $polygon[($i + 1) % $count]];
            $n[0] += ($a[1] - $b[1]) * ($a[2] + $b[2]);
            $n[1] += ($a[2] - $b[2]) * ($a[0] + $b[0]);
            $n[2] += ($a[0] - $b[0]) * ($a[1] + $b[1]);
        }
        $length = sqrt($n[0] ** 2 + $n[1] ** 2 + $n[2] ** 2);

        return $length > 0 ? rad2deg(acos(min(1.0, abs($n[1]) / $length))) : 90.0;
    }

    /**
     * A street or path: a thin strip along every leg of its centre line. Footways
     * lie a little higher than carriageways, so where they cross, the path shows.
     *
     * @param list<array{float, float}> $points
     *
     * @return list<Part>
     */
    private function road(string $label, array $points, float $width, bool $footway): array
    {
        $material = $footway
            ? $this->material('footway', self::FOOTWAY_COLOUR, ProceduralTexture::Paving, self::FOOTWAY_TEXTURE_SIZE)
            : $this->material('carriageway', self::ASPHALT_COLOUR, ProceduralTexture::Asphalt, self::ASPHALT_TEXTURE_SIZE);
        $top = $footway ? self::FOOTWAY_TOP : self::CARRIAGEWAY_TOP;

        return array_map(
            static fn (Part $part): Part => new Part(null, $part->label, $part->shape, $part->material, new Vector3($part->position->x, $top - self::ROAD_THICKNESS / 2, $part->position->z), $part->rotation),
            $this->along($label, $points, self::ROAD_THICKNESS, $width, $material),
        );
    }

    /**
     * A box along every leg of a line, a little longer so the corners close.
     *
     * @param list<array{float, float}> $points
     *
     * @return list<Part>
     */
    private function along(string $label, array $points, float $height, float $thickness, string $material): array
    {
        $parts = [];
        for ($i = 1, $count = count($points); $i < $count; ++$i) {
            [$ax, $az] = $points[$i - 1];
            [$bx, $bz] = $points[$i];
            $length = hypot($bx - $ax, $bz - $az);
            if ($length < 1) {
                continue;
            }
            $parts[] = new Part(
                null,
                $label,
                new Box($length + $thickness, $height, $thickness),
                $material,
                new Vector3(($ax + $bx) / 2, $height / 2, ($az + $bz) / 2),
                new Vector3(0, atan2(-($bz - $az), $bx - $ax), 0),
            );
        }

        return $parts;
    }

    /**
     * The mean width of an outline (twice its area over its perimeter), in its units.
     *
     * @param list<array{float, float}> $points
     */
    private static function meanWidth(array $points): float
    {
        [$area, $perimeter] = [0.0, 0.0];
        for ($i = 0, $n = count($points); $i < $n; ++$i) {
            [$a, $b] = [$points[$i], $points[($i + 1) % $n]];
            $area += $a[0] * $b[1] - $b[0] * $a[1];
            $perimeter += hypot($b[0] - $a[0], $b[1] - $a[1]);
        }

        return $perimeter > 0 ? abs($area) / $perimeter : 0.0;
    }

    /**
     * Water in a frame of coping stones, flush with the lawn.
     *
     * @return list<Part>
     */
    private function pool(string $label, float $x, float $z, float $angle, float $width, float $depth): array
    {
        $this->assembly->material(self::WATER, new Material('#3d9ec4', roughness: 0.04, clearcoat: 1.0));
        $this->assembly->material(self::COPING, new Material('#d8d3c8', roughness: 0.8, texture: ProceduralTexture::Paving, textureSize: 0.8));
        $c = self::COPING_WIDTH;
        $parts = [
            new Part(null, $label, new Box($width, 20, $depth), self::WATER, new Vector3(0, 0, 0)),
            new Part(null, $label, new Box($width + 2 * $c, self::PAVING, $c), self::COPING, new Vector3(0, self::SURFACE - self::PAVING / 2, $depth / 2 + $c / 2)),
            new Part(null, $label, new Box($width + 2 * $c, self::PAVING, $c), self::COPING, new Vector3(0, self::SURFACE - self::PAVING / 2, -$depth / 2 - $c / 2)),
            new Part(null, $label, new Box($c, self::PAVING, $depth), self::COPING, new Vector3($width / 2 + $c / 2, self::SURFACE - self::PAVING / 2, 0)),
            new Part(null, $label, new Box($c, self::PAVING, $depth), self::COPING, new Vector3(-$width / 2 - $c / 2, self::SURFACE - self::PAVING / 2, 0)),
        ];

        return array_map(static fn (Part $part): Part => $part->placedAt($x, $z, $angle), $parts);
    }

    /**
     * A material in the customer's colour, one per role and colour.
     */
    private function colour(string $role, string $hex, ?ProceduralTexture $texture = null): string
    {
        return $this->material($role, $hex, $texture, ProceduralTexture::RoofTiles === $texture ? self::TILE_TEXTURE_SIZE : self::PLASTER_TEXTURE_SIZE);
    }

    private function material(string $role, string $hex, ?ProceduralTexture $texture, float $textureSize): string
    {
        $key = self::MATERIAL_PREFIX.$role.'_'.ltrim($hex, '#');
        $this->assembly->material($key, new Material($hex, roughness: 0.85, texture: $texture, textureSize: $textureSize));

        return $key;
    }

    private function hedge(): string
    {
        $this->assembly->material(self::HEDGE, new Material('#3e5a2b', roughness: 1.0));

        return self::HEDGE;
    }

    private function door(): string
    {
        $this->assembly->material(self::DOOR, new Material('#d6d4cf', roughness: 0.5, clearcoat: 0.2));

        return self::DOOR;
    }

    /** Window frames and glass of the House, lit in the evening. */
    private function houseMaterials(): void
    {
        $materials = House::materials();
        $this->assembly->material(House::MATERIAL_FRAME, $materials[House::MATERIAL_FRAME]);
        $this->assembly->material(House::MATERIAL_GLASS, $materials[House::MATERIAL_GLASS]);
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
}
