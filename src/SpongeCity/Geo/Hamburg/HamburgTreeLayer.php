<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo\Hamburg;

use App\SpongeCity\Geo\DataSource;
use App\SpongeCity\Geo\GeoPoint;
use App\SpongeCity\Geo\LocalProjection;
use App\SpongeCity\Geo\MapArea;
use App\SpongeCity\Geo\MapExtent;
use App\SpongeCity\Geo\MapLayer;
use App\SpongeCity\Geo\SiteImporter;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Hamburg's street trees on the site, from the tree register with their measured heights.
 * The register knows street trees only: trees in yards and parks stay as OpenStreetMap maps them.
 */
#[AsTaggedItem(priority: self::PRIORITY)]
final readonly class HamburgTreeLayer implements MapLayer
{
    public const string SOURCE = 'trees';
    private const int PRIORITY = 10;
    /** an OpenStreetMap tree this close to a registered one is the same tree */
    private const float SAME_TREE_METRES = 3.0;
    private const float MILLIMETRES_PER_METRE = 1000.0;

    public function __construct(
        private HamburgTrees $trees,
    ) {
    }

    public function covers(MapArea $area): bool
    {
        return MapExtent::Site === $area->extent && Hamburg::covers($area->centre);
    }

    public function apply(array $elements, MapArea $area): array
    {
        $trees = $this->trees->within($area->box());

        return [...array_values(array_filter($elements, static fn (array $e): bool => !self::isTree($e) || !self::nearAny($e, $trees))), ...$trees];
    }

    public function source(): DataSource
    {
        return new DataSource(self::SOURCE, 'Straßenbaumkataster Hamburg', Hamburg::BUKEA, 'updated yearly', Hamburg::LICENCE, HamburgApi::BASE_URL.'/'.HamburgTrees::DATASET,
            sprintf('Street trees only (trees in yards and parks come from OpenStreetMap). Crown diameters are recorded. Heights from the 3D Straßenbaumkataster (LGV, dl-de/by-2-0): the airborne laser scan of April 2022 within the crown above the ground, which may differ from reality; trees it could not measure are estimated as %.1f × crown + %.0f m.', HamburgTrees::HEIGHT_PER_CROWN, HamburgTrees::TRUNK_METRES));
    }

    /**
     * @param array<string, mixed> $element
     */
    private static function isTree(array $element): bool
    {
        return 'node' === ($element['type'] ?? null) && 'tree' === ($element['tags'][SiteImporter::TAG_NATURAL] ?? null);
    }

    /**
     * @param array<string, mixed>       $node
     * @param list<array<string, mixed>> $others
     */
    private static function nearAny(array $node, array $others): bool
    {
        if (!isset($node['lat'], $node['lon'])) {
            return false;
        }
        $projection = new LocalProjection(new GeoPoint((float) $node['lat'], (float) $node['lon']));
        foreach ($others as $other) {
            [$x, $z] = $projection->toPlan((float) $other['lat'], (float) $other['lon']);
            if (hypot($x, $z) <= self::SAME_TREE_METRES * self::MILLIMETRES_PER_METRE) {
                return true;
            }
        }

        return false;
    }
}
