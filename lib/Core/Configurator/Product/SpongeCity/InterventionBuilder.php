<?php

declare(strict_types=1);

namespace Core\Configurator\Product\SpongeCity;

use Core\Configurator\Assembly\Assembly;
use Core\Configurator\Assembly\Measure;
use Core\Configurator\Assembly\Model;
use Core\Configurator\Assembly\Part;
use Core\Configurator\Assembly\Vector3;
use Core\Configurator\Product\SpongeCity\GreenRoof\GreenRoofs;
use Core\Configurator\Product\SpongeCity\Impact\Polygon;
use Core\Configurator\Scene\Environment;
use Core\Configurator\Scene\GardenModels;
use Core\Configurator\Scene\Material;
use Core\Configurator\Scene\ProceduralTexture;
use Core\Configurator\Scenery\Slab;
use Core\Configurator\Site\BuildingType;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteBuilder;
use Core\Configurator\Site\SiteObject as O;

/**
 * Turns the plot and the planned interventions of a site into the product:
 * parts with an SKU, so they are on the bill of quantities, priced, framed by
 * the camera and named when hovered. The surrounding city is built by SiteBuilder.
 */
final class InterventionBuilder
{
    public const string LABEL_PREFIX = 'spongecity.part.';

    public const string MATERIAL_PLOT_PREFIX = 'plot_';
    public const string MATERIAL_RAINGARDEN = 'raingarden';
    public const string MATERIAL_PLANTING = 'planting';
    public const string MATERIAL_SAIL = 'shadesail';
    public const string MATERIAL_FACADE_GREEN = 'facade_green';
    public const string MATERIAL_COOL_SURFACE = 'cool_surface';
    public const string MATERIAL_PAVERS = 'permeable_pavers';
    public const string MATERIAL_GRAVEL = 'permeable_gravel';
    public const string MATERIAL_GRASS_PAVERS = 'permeable_grass';
    public const string MATERIAL_GREENROOF_PREFIX = 'greenroof_';
    /** what the view needs beyond a part's shape: its kind and, for a rain garden, its depth in metres */
    public const string APPEARANCE_KIND = 'kind';
    public const string APPEARANCE_DEPTH = 'depth';
    public const string APPEARANCE_PAVING = 'paving';
    /** a green roof: extensive or intensive, the gravel strip's width and the build-up's thickness, metres */
    public const string APPEARANCE_VEGETATION = 'vegetation';
    public const string APPEARANCE_STRIP = 'strip';
    public const string APPEARANCE_THICKNESS = 'thickness';
    /** millimetres of a green roof's build-up, drawn on the roof slab */
    private const array GREENROOF_THICKNESS = [O::KIND_EXTENSIVE => 120, O::KIND_INTENSIVE => 350];
    private const array GREENROOF_SKU = [O::KIND_EXTENSIVE => SpongeCitySku::GREENROOF_EXTENSIVE, O::KIND_INTENSIVE => SpongeCitySku::GREENROOF_INTENSIVE];
    private const array GREENROOF_COLOURS = [O::KIND_EXTENSIVE => '#7d8f4e', O::KIND_INTENSIVE => '#5f8a3c'];
    private const float MILLIMETRES_PER_METRE = 1000.0;
    private const float CUBIC_MILLIMETRES_PER_CUBIC_METRE = 1e9;

    /** layers of the ground, millimetres: the plot lies lowest, interventions on top of existing paving */
    private const int PLOT_TOP = 4;
    private const int PLOT_THICKNESS = 40;
    /** a ground area of the plot (a lawn on an asphalt plot): over the plot, under every intervention */
    private const int AREA_TOP = 7;
    private const int LAYER_THICKNESS = 40;
    private const int PERMEABLE_TOP = 14;
    private const int PLANTING_TOP = 18;
    /** a coating lies on the asphalt, under everything planted */
    private const int COOL_SURFACE_TOP = 10;
    /** how far the climbers and their trellis stand out from the wall, millimetres */
    private const int FACADE_GREEN_DEPTH = 300;
    private const int RAINGARDEN_TOP = 22;
    private const int SAIL_THICKNESS = 30;
    private const string TREE_VARIANT = 'tree_01';
    /** a site whose median building is this low (millimetres), or with this share of houses, lies in a suburb */
    private const int SUBURBAN_HEIGHT = 10_000;
    private const float SUBURBAN_HOUSE_SHARE = 0.4;
    private const array HOUSE_USES = [BuildingType::House->value, BuildingType::Terrace->value, BuildingType::Garage->value];
    private const float SQUARE_MILLIMETRES_PER_SQUARE_METRE = 1.0;

    private const array SURFACE_COLORS = [
        O::SURFACE_ASPHALT => '#4a4b4e',
        O::SURFACE_CONCRETE => '#a8a6a0',
        O::SURFACE_GRAVEL => '#b4a78f',
        O::SURFACE_LAWN => '#6f9a4a',
        O::SURFACE_SOIL => '#6b5338',
    ];

    private const array PERMEABLE_SKU = [
        O::KIND_PAVERS => SpongeCitySku::PERMEABLE_PAVERS,
        O::KIND_GRAVEL => SpongeCitySku::PERMEABLE_GRAVEL,
        O::KIND_GRASS_PAVERS => SpongeCitySku::PERMEABLE_GRASS_PAVERS,
    ];

    private const array PERMEABLE_MATERIAL = [
        O::KIND_PAVERS => self::MATERIAL_PAVERS,
        O::KIND_GRAVEL => self::MATERIAL_GRAVEL,
        O::KIND_GRASS_PAVERS => self::MATERIAL_GRASS_PAVERS,
    ];

    /** millimetres of a green roof's build-up above its roof */
    public static function greenRoofThickness(string $kind): int
    {
        return self::GREENROOF_THICKNESS[$kind] ?? self::GREENROOF_THICKNESS[O::KIND_EXTENSIVE];
    }

    /**
     * Whether the site lies among low houses (its own buildings, imported from the map):
     * most of them no higher than SUBURBAN_HEIGHT, or many of them houses.
     */
    public static function isSuburban(Site $site): bool
    {
        $buildings = $site->ofType(O::BUILDING);
        if ([] === $buildings) {
            return false;
        }
        $heights = array_map(static fn (array $b): float => is_numeric($b[O::HEIGHT] ?? null) ? (float) $b[O::HEIGHT] : 0.0, $buildings);
        sort($heights);
        $houses = count(array_filter($buildings, static fn (array $b): bool => in_array($b[O::USE] ?? null, self::HOUSE_USES, true)));

        return $heights[intdiv(count($heights), 2)] <= self::SUBURBAN_HEIGHT || $houses / count($buildings) >= self::SUBURBAN_HOUSE_SHARE;
    }

    public function build(Site $site): Assembly
    {
        $assembly = new Assembly();
        $this->materials($assembly);
        $assembly->environment(Environment::AERIAL, true);
        $assembly->environment(Environment::GARDEN_PATH, false);
        $assembly->environment(Environment::SUBURBAN, self::isSuburban($site));

        $plotSurface = O::SURFACE_ASPHALT;
        foreach ($site->ofType(O::PLOT) as $plot) {
            $plotSurface = is_string($plot[O::SURFACE] ?? null) ? $plot[O::SURFACE] : O::SURFACE_ASPHALT;
            $part = Slab::onGround(self::LABEL_PREFIX.O::PLOT, $this->ground($assembly, $plotSurface), Site::points($plot), self::PLOT_THICKNESS, self::PLOT_TOP - self::PLOT_THICKNESS);
            // the whole plot frames the camera, but it is not an article: counted false keeps it off the bill
            $assembly->add(new Part(SpongeCitySku::PLOT, $part->label, $part->shape, $part->material, $part->position, $part->rotation, counted: false, draped: true));
        }
        // the plot's areas whose ground is known (a lawn, from OpenStreetMap or as a person said) and
        // differs from the plot's: drawn as that ground, so the view shows the place as it looks. An
        // area of unknown ground is not drawn: nothing is guessed.
        foreach ($site->ofType(O::AREA) as $area) {
            $surface = is_string($area[O::SURFACE] ?? null) ? $area[O::SURFACE] : O::SURFACE_UNKNOWN;
            $points = Site::points($area);
            if (!isset(self::SURFACE_COLORS[$surface]) || $surface === $plotSurface || count($points) < 3) {
                continue;
            }
            $part = Slab::onGround(self::LABEL_PREFIX.O::AREA, $this->ground($assembly, $surface), $points, self::LAYER_THICKNESS, self::AREA_TOP - self::LAYER_THICKNESS);
            // its own SKU: the plot map's preview reshapes the plot's part (SKU_PLOT in scene_renderer.js), not these
            $assembly->add(new Part(SpongeCitySku::AREA, $part->label, $part->shape, $part->material, $part->position, $part->rotation, counted: false, draped: true));
        }

        foreach ($site->objects as $object) {
            $type = is_string($object[O::TYPE] ?? null) ? $object[O::TYPE] : '';
            if (!in_array($type, O::INTERVENTIONS, true)) {
                continue;
            }
            $label = Site::label($object) ?? self::LABEL_PREFIX.$type;
            if (O::GREENROOF === $type) {
                // on its building's roof levels, raised with the building: not draped, not on the ground
                foreach ($this->greenRoof($site, $object, $label) as $part) {
                    $assembly->add($part);
                }
                continue;
            }
            // trees and sails stand on their ground; the surfaces are draped over the terrain by the view
            $upright = in_array($type, O::UPRIGHT, true);
            $ground = $upright && is_numeric($object[O::GROUND] ?? null) ? (float) $object[O::GROUND] : 0.0;
            foreach ($this->parts($object, $type, $label) as $part) {
                $assembly->add($upright ? $part->raisedBy($ground) : $part->drapedOverTerrain());
            }
            $points = Site::points($object);
            if ([] !== $points) {
                $assembly->keepClear(min(array_column($points, 0)), max(array_column($points, 0)), min(array_column($points, 1)), max(array_column($points, 1)));
            }
        }

        return $assembly;
    }

    /**
     * @param array<string, mixed> $object
     *
     * @return list<Part>
     */
    private function parts(array $object, string $type, string $label): array
    {
        $num = static fn (string $key): float => is_numeric($object[$key] ?? null) ? (float) $object[$key] : 0.0;
        $points = Site::points($object);
        $area = Measure::area(Polygon::area($points), self::SQUARE_MILLIMETRES_PER_SQUARE_METRE);

        return match ($type) {
            O::NEWTREE => [
                new Part(SpongeCitySku::TREE, $label, Model::ofHeight(GardenModels::TREES, self::TREE_VARIANT, $num(O::HEIGHT)), position: new Vector3($num(O::X), 0, $num(O::Z))),
                // the tree trench lies under it, out of sight: on the bill by its volume, not drawn
                ...($num(O::TRENCH_VOLUME) > 0 ? [new Part(SpongeCitySku::TREE_TRENCH, $label, measure: Measure::volume($num(O::TRENCH_VOLUME) * self::CUBIC_MILLIMETRES_PER_CUBIC_METRE))] : []),
            ],
            // the view draws a basin of its ponding depth, planted, with water in it
            O::RAINGARDEN => [
                ...$this->slab(SpongeCitySku::RAINGARDEN, $label, self::MATERIAL_RAINGARDEN, $points, self::RAINGARDEN_TOP, $area, [
                    self::APPEARANCE_KIND => O::RAINGARDEN,
                    self::APPEARANCE_DEPTH => $num(O::STORAGE) / self::MILLIMETRES_PER_METRE,
                ]),
                // the trench lies under the garden, out of sight: on the bill by its volume, not drawn
                ...($num(O::TRENCH) > 0 ? [new Part(SpongeCitySku::RAINGARDEN_TRENCH, $label, measure: Measure::volume(Polygon::area($points) * $num(O::TRENCH)))] : []),
            ],
            O::PLANTING => $this->slab(SpongeCitySku::PLANTING, $label, self::MATERIAL_PLANTING, $points, self::PLANTING_TOP, $area),
            O::COOLSURFACE => $this->slab(SpongeCitySku::COOL_SURFACE, $label, self::MATERIAL_COOL_SURFACE, $points, self::COOL_SURFACE_TOP, $area),
            O::FACADEGREEN => $this->facadeGreen($points, $num(O::HEIGHT), $label),
            O::PERMEABLE => $this->slab(
                self::PERMEABLE_SKU[$object[O::KIND] ?? O::KIND_PAVERS] ?? SpongeCitySku::PERMEABLE_PAVERS,
                $label,
                self::PERMEABLE_MATERIAL[$object[O::KIND] ?? O::KIND_PAVERS] ?? self::MATERIAL_PAVERS,
                $points,
                self::PERMEABLE_TOP,
                $area,
                // the view lays its pavers (or grass pavers, or gravel) at their real size, with an edging
                [self::APPEARANCE_KIND => O::PERMEABLE, self::APPEARANCE_PAVING => is_string($object[O::KIND] ?? null) ? $object[O::KIND] : O::KIND_PAVERS],
            ),
            O::SHADESAIL => [new Part(
                SpongeCitySku::SHADESAIL,
                $label,
                Slab::onGround($label, self::MATERIAL_SAIL, $points, self::SAIL_THICKNESS, $num(O::Y))->shape,
                self::MATERIAL_SAIL,
                new Vector3(0, $num(O::Y) + self::SAIL_THICKNESS / 2, 0),
                new Vector3(M_PI / 2, 0, 0),
                $area,
            )],
            default => [],
        };
    }

    /**
     * Façade greening: a thin green wall standing along each segment of its line, as high as the
     * climbers grow, priced per m² of wall.
     *
     * @param list<array{0: float, 1: float}> $points
     *
     * @return list<Part>
     */
    private function facadeGreen(array $points, float $height, string $label): array
    {
        $parts = [];
        $half = self::FACADE_GREEN_DEPTH / 2;
        for ($i = 1, $n = count($points); $i < $n; ++$i) {
            [$x1, $z1] = $points[$i - 1];
            [$x2, $z2] = $points[$i];
            $length = hypot($x2 - $x1, $z2 - $z1);
            if ($length <= 0.0 || $height <= 0.0) {
                continue;
            }
            // a strip FACADE_GREEN_DEPTH wide around the segment, extruded upwards
            $nx = -($z2 - $z1) / $length * $half;
            $nz = ($x2 - $x1) / $length * $half;
            $strip = [[$x1 + $nx, $z1 + $nz], [$x2 + $nx, $z2 + $nz], [$x2 - $nx, $z2 - $nz], [$x1 - $nx, $z1 - $nz]];
            $slab = Slab::onGround($label, self::MATERIAL_FACADE_GREEN, $strip, (int) round($height), 0);
            $parts[] = new Part(SpongeCitySku::FACADE_GREEN, $label, $slab->shape, $slab->material, $slab->position, $slab->rotation, Measure::area($length, $height));
        }

        return $parts;
    }

    /**
     * A green roof: a planted build-up on each roof level it covers (priced per m² of roof, the gravel
     * strip included), and its building's set-up (survey, crane, scaffolding) once, not drawn.
     *
     * @param array<string, mixed> $object
     *
     * @return list<Part>
     */
    private function greenRoof(Site $site, array $object, string $label): array
    {
        $levels = GreenRoofs::levelsOf($site, $object);
        $building = GreenRoofs::buildingOf($site, $object);
        if ([] === $levels || null === $building) {
            return [];
        }
        $kind = in_array($object[O::KIND] ?? null, O::GREENROOF_KINDS, true) ? (string) $object[O::KIND] : O::KIND_EXTENSIVE;
        $thickness = self::GREENROOF_THICKNESS[$kind];
        $ground = is_numeric($building[O::GROUND] ?? null) ? (float) $building[O::GROUND] : 0.0;
        $parts = [new Part(SpongeCitySku::GREENROOF_SETUP, $label, measure: Measure::piece())];
        foreach ($levels as $level) {
            $slab = Slab::onGround($label, self::MATERIAL_GREENROOF_PREFIX.$kind, $level->points, $thickness, $level->height + SiteBuilder::FOOTPRINT_ROOF);
            $parts[] = (new Part(
                self::GREENROOF_SKU[$kind],
                $label,
                $slab->shape,
                $slab->material,
                $slab->position,
                $slab->rotation,
                Measure::area($level->grossSquareMetres * 1_000_000, self::SQUARE_MILLIMETRES_PER_SQUARE_METRE),
                appearance: [
                    self::APPEARANCE_KIND => O::GREENROOF,
                    self::APPEARANCE_VEGETATION => $kind,
                    self::APPEARANCE_STRIP => GreenRoofs::GRAVEL_STRIP_MM / self::MILLIMETRES_PER_METRE,
                    self::APPEARANCE_THICKNESS => $thickness / self::MILLIMETRES_PER_METRE,
                ],
            ))->raisedBy($ground);
        }

        return $parts;
    }

    /**
     * @param list<array{0: float, 1: float}> $points
     *
     * @return list<Part>
     */
    private function slab(string $sku, string $label, string $material, array $points, int $top, Measure $measure, ?array $appearance = null): array
    {
        $slab = Slab::onGround($label, $material, $points, self::LAYER_THICKNESS, $top - self::LAYER_THICKNESS);

        return [new Part($sku, $label, $slab->shape, $material, $slab->position, $slab->rotation, $measure, appearance: $appearance)];
    }

    /** the material of a ground (a plot's, an area's): one per surface, shared */
    private function ground(Assembly $assembly, string $surface): string
    {
        $material = self::MATERIAL_PLOT_PREFIX.$surface;
        $assembly->material($material, new Material(self::SURFACE_COLORS[$surface] ?? self::SURFACE_COLORS[O::SURFACE_ASPHALT], roughness: 0.95));

        return $material;
    }

    private function materials(Assembly $assembly): void
    {
        $assembly->material(self::MATERIAL_RAINGARDEN, new Material('#46763a', roughness: 1.0));
        $assembly->material(self::MATERIAL_PLANTING, new Material('#6c9b46', roughness: 1.0));
        $assembly->material(self::MATERIAL_FACADE_GREEN, new Material('#4f7f35', roughness: 1.0));
        $assembly->material(self::MATERIAL_COOL_SURFACE, new Material('#c9c6bd', roughness: 0.9));
        $assembly->material(self::MATERIAL_SAIL, new Material('#e8c872', roughness: 0.9, opacity: 0.82));
        $assembly->material(self::MATERIAL_PAVERS, new Material('#c9bfae', roughness: 0.85, texture: ProceduralTexture::Paving, textureSize: 0.6));
        $assembly->material(self::MATERIAL_GRAVEL, new Material('#cdbf9f', roughness: 1.0, texture: ProceduralTexture::Stones, textureSize: 0.4));
        foreach (self::GREENROOF_COLOURS as $kind => $colour) {
            $assembly->material(self::MATERIAL_GREENROOF_PREFIX.$kind, new Material($colour, roughness: 1.0));
        }
        $assembly->material(self::MATERIAL_GRASS_PAVERS, new Material('#85a65e', roughness: 1.0, texture: ProceduralTexture::Paving, textureSize: 0.5));
    }
}
