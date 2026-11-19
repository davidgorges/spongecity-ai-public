<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo\Hamburg;

use App\SpongeCity\Geo\BuildingTags;
use App\SpongeCity\Geo\DataSource;
use App\SpongeCity\Geo\GeoBox;
use App\SpongeCity\Geo\MapArea;
use App\SpongeCity\Geo\MapExtent;
use App\SpongeCity\Geo\MapLayer;
use Core\Configurator\Site\SiteObject;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Hamburg's buildings in place of OpenStreetMap's: footprints, uses and storeys from the
 * cadastre (ALKIS), heights and roofs from the 3D building model. On the site, out to its
 * reach and with their roof models; around it, plain.
 */
#[AsTaggedItem(priority: self::PRIORITY)]
final readonly class HamburgBuildingLayer implements MapLayer
{
    public const string SOURCE = 'buildings';
    /** before the trees: the sources are listed in this order */
    private const int PRIORITY = 20;
    private const float MILLIMETRES_PER_METRE = 1000.0;

    public function __construct(
        private HamburgBuildings $buildings,
    ) {
    }

    public function covers(MapArea $area): bool
    {
        return Hamburg::covers($area->centre);
    }

    public function apply(array $elements, MapArea $area): array
    {
        $buildings = MapExtent::Site === $area->extent
            // the site keeps buildings out to its reach (cut there)
            ? $this->buildings->within(GeoBox::around($area->centre, SiteObject::REACH / self::MILLIMETRES_PER_METRE), detailed: true)
            : $this->buildings->within($area->box());

        return [...array_values(array_filter($elements, static fn (array $e): bool => !self::isBuilding($e))), ...$buildings];
    }

    public function source(): DataSource
    {
        return new DataSource(self::SOURCE, 'ALKIS Gebäude + 3D-Gebäudemodell LoD2-DE Hamburg', Hamburg::LGV, 'current (LoD2 2026)', Hamburg::LICENCE, HamburgApi::BASE_URL.'/'.HamburgBuildings::DATASET_LOD2,
            'Official footprints, uses and storeys; heights measured (LoD2). Pitched roofs are drawn as a gable or hip where that matches the 3D model, else from the model\'s own surfaces (LoD3 where it has the building).');
    }

    /**
     * @param array<string, mixed> $element
     */
    private static function isBuilding(array $element): bool
    {
        return 'way' === ($element['type'] ?? null) && isset($element['tags'][BuildingTags::TAG_BUILDING]);
    }
}
