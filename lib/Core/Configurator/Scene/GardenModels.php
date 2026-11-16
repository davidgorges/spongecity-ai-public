<?php

declare(strict_types=1);

namespace Core\Configurator\Scene;

/**
 * Model libraries for garden scenery, shared by all outdoor products. A
 * library is installed with
 * `symfony console app:configurator:import-model <file> <library> --prefix <variant prefix>`.
 */
final class GardenModels
{
    /** 15 garden bushes, 0.6–1.3 m, variants bush_01 … bush_15 */
    public const string BUSHES = 'garden_bushes';
    public const string BUSH_VARIANT_PREFIX = 'bush';
    public const int BUSH_VARIANTS = 15;

    /** CC0 (Poly Haven) potted plants in terracotta pots, ~1 m: plant_01 (fig-like), plant_02 (large leaves) */
    public const string PLANTS = 'garden_plants';
    public const string PLANT_FIG = 'plant_01';
    public const string PLANT_LEAVES = 'plant_02';

    /** CC0 (Poly Haven) jacaranda, variant tree_01; placed by the viewer's garden on the ultra tier */
    public const string TREES = 'garden_trees';

    /** built in the browser: lounge_sofa, lounge_armchair, coffee_table, side_table, rug, olive_planter, fire_pit */
    public const string FURNITURE = 'garden_furniture';
    public const string LOUNGE_SOFA = 'lounge_sofa';
    public const string LOUNGE_ARMCHAIR = 'lounge_armchair';
    public const string COFFEE_TABLE = 'coffee_table';
    public const string SIDE_TABLE = 'side_table';
    public const string RUG = 'rug';
    public const string OLIVE_PLANTER = 'olive_planter';
    /** corten bowl with logs on a gravel ring (the width); burns at sunset and in the evening */
    public const string FIRE_PIT = 'fire_pit';

    public static function bush(int $number): string
    {
        return sprintf('%s_%02d', self::BUSH_VARIANT_PREFIX, ($number - 1) % self::BUSH_VARIANTS + 1);
    }

    private function __construct()
    {
    }
}
