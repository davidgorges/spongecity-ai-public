<?php

declare(strict_types=1);

namespace App\SpongeCity\Placement;

use Core\Configurator\Site\SiteObject as O;

/**
 * A coherent part of a plot's open ground, the way a landscape architect reads a site: bounded by
 * buildings, paths and the areas on the map, used for one thing (yard, car park, access lane …)
 * and made of one surface today. Its use decides what the surface may become.
 */
final readonly class Zone
{
    /** where the zone's use comes from */
    public const string FROM_MAP = 'map';
    public const string FROM_USER = 'user';
    /** open ground between buildings and paths, nothing known about it: taken as a yard */
    public const string ASSUMED = 'assumed';

    private const float MILLIMETRES_PER_METRE = 1000.0;

    /**
     * @param array<int, true>                $cells   its cells of the open ground
     * @param list<array{0: float, 1: float}> $outline plan metres, straight lines
     */
    public function __construct(
        public string $id,
        public string $use,
        public string $surface,
        public string $source,
        public string $label,
        public array $cells,
        public array $outline,
        public float $areaM2,
        /** the site's area object it stands for; null for ground between buildings and paths */
        public ?string $objectId = null,
    ) {
    }

    /**
     * @return list<array{0: int, 1: int}> the outline in plan millimetres
     */
    public function points(): array
    {
        return array_map(static fn (array $p): array => [(int) round($p[0] * self::MILLIMETRES_PER_METRE), (int) round($p[1] * self::MILLIMETRES_PER_METRE)], $this->outline);
    }

    /**
     * @return array{id: string, use: string, surface: string, source: string, label: string, area_m2: float, points: list<array{0: int, 1: int}>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            O::USE => $this->use,
            O::SURFACE => $this->surface,
            'source' => $this->source,
            O::LABEL => $this->label,
            'area_m2' => round($this->areaM2),
            O::POINTS => $this->points(),
        ];
    }
}
