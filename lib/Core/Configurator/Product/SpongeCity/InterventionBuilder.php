<?php

declare(strict_types=1);

namespace Core\Configurator\Product\SpongeCity;

use Core\Configurator\Assembly\Assembly;
use Core\Configurator\Assembly\Measure;
use Core\Configurator\Assembly\Model;
use Core\Configurator\Assembly\Part;
use Core\Configurator\Assembly\Vector3;
use Core\Configurator\Product\SpongeCity\Impact\Polygon;
use Core\Configurator\Scene\Environment;
use Core\Configurator\Scene\GardenModels;
use Core\Configurator\Scene\Material;
use Core\Configurator\Scene\ProceduralTexture;
use Core\Configurator\Scenery\Slab;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\BuildingType;
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
    public const string MATERIAL_PAVERS = 'permeable_pavers';
    public const string MATERIAL_GRAVEL = 'permeable_gravel';
    public const string MATERIAL_GRASS_PAVERS = 'permeable_grass';
    /** what the view needs beyond a part's shape: its kind and, for a rain garden, its depth in metres */
    public const string APPEARANCE_KIND = 'kind';
    public const string APPEARANCE_DEPTH = 'depth';
    public const string APPEARANCE_PAVING = 'paving';
    private const float MILLIMETRES_PER_METRE = 1000.0;

    /** layers of the ground, millimetres: the plot lies lowest, interventions on top of existing paving */
    private const int PLOT_TOP = 4;
    private const int PLOT_THICKNESS = 40;
    private const int LAYER_THICKNESS = 40;
    private const int PERMEABLE_TOP = 14;
    private const int PLANTING_TOP = 18;
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

        foreach ($site->ofType(O::PLOT) as $plot) {
            $surface = is_string($plot[O::SURFACE] ?? null) ? $plot[O::SURFACE] : O::SURFACE_ASPHALT;
            $material = self::MATERIAL_PLOT_PREFIX.$surface;
            $assembly->material($material, new Material(self::SURFACE_COLORS[$surface] ?? self::SURFACE_COLORS[O::SURFACE_ASPHALT], roughness: 0.95));
            $part = Slab::onGround(self::LABEL_PREFIX.O::PLOT, $material, Site::points($plot), self::PLOT_THICKNESS, self::PLOT_TOP - self::PLOT_THICKNESS);
            // the whole plot frames the camera, but it is not an article: counted false keeps it off the bill
            $assembly->add(new Part(SpongeCitySku::PLOT, $part->label, $part->shape, $part->material, $part->position, $part->rotation, counted: false, draped: true));
            // the plot's areas (a lawn, a car park, what a person said a zone's ground is) are not drawn:
            // they are facts for the balance and the placement, the view shows the place as it looks
        }

        foreach ($site->objects as $object) {
            $type = is_string($object[O::TYPE] ?? null) ? $object[O::TYPE] : '';
            if (!in_array($type, O::INTERVENTIONS, true)) {
                continue;
            }
            $label = is_string($object[O::LABEL] ?? null) ? $object[O::LABEL] : self::LABEL_PREFIX.$type;
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
            O::NEWTREE => [new Part(SpongeCitySku::TREE, $label, Model::ofHeight(GardenModels::TREES, self::TREE_VARIANT, $num(O::HEIGHT)), position: new Vector3($num(O::X), 0, $num(O::Z)))],
            // the view draws a basin of its ponding depth, planted, with water in it
            O::RAINGARDEN => $this->slab(SpongeCitySku::RAINGARDEN, $label, self::MATERIAL_RAINGARDEN, $points, self::RAINGARDEN_TOP, $area, [
                self::APPEARANCE_KIND => O::RAINGARDEN,
                self::APPEARANCE_DEPTH => $num(O::STORAGE) / self::MILLIMETRES_PER_METRE,
            ]),
            O::PLANTING => $this->slab(SpongeCitySku::PLANTING, $label, self::MATERIAL_PLANTING, $points, self::PLANTING_TOP, $area),
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
     * @param list<array{0: float, 1: float}> $points
     *
     * @return list<Part>
     */
    private function slab(string $sku, string $label, string $material, array $points, int $top, Measure $measure, ?array $appearance = null): array
    {
        $slab = Slab::onGround($label, $material, $points, self::LAYER_THICKNESS, $top - self::LAYER_THICKNESS);

        return [new Part($sku, $label, $slab->shape, $material, $slab->position, $slab->rotation, $measure, appearance: $appearance)];
    }

    private function materials(Assembly $assembly): void
    {
        $assembly->material(self::MATERIAL_RAINGARDEN, new Material('#46763a', roughness: 1.0));
        $assembly->material(self::MATERIAL_PLANTING, new Material('#6c9b46', roughness: 1.0));
        $assembly->material(self::MATERIAL_SAIL, new Material('#e8c872', roughness: 0.9, opacity: 0.82));
        $assembly->material(self::MATERIAL_PAVERS, new Material('#c9bfae', roughness: 0.85, texture: ProceduralTexture::Paving, textureSize: 0.6));
        $assembly->material(self::MATERIAL_GRAVEL, new Material('#cdbf9f', roughness: 1.0, texture: ProceduralTexture::Stones, textureSize: 0.4));
        $assembly->material(self::MATERIAL_GRASS_PAVERS, new Material('#85a65e', roughness: 1.0, texture: ProceduralTexture::Paving, textureSize: 0.5));
    }
}
