<?php

declare(strict_types=1);

namespace Core\Configurator\Scenery;

use Core\Configurator\Assembly\Model;
use Core\Configurator\Assembly\Part;
use Core\Configurator\Assembly\Vector3;
use Core\Configurator\Scene\GardenModels;

/**
 * Garden scenery every outdoor product stages the same way: shrubs, potted
 * plants, furniture and the arrangements made of them. Parts never have a
 * SKU; furniture and potted plants are decor (the customer can hide them).
 *
 * Arrangements are built around their own origin (x across, z towards the
 * front, y up); place them with Part::placedAt().
 */
final class Furnishing
{
    public const int ARMCHAIR_WIDTH = 850;
    public const int SIDE_TABLE_WIDTH = 480;
    public const int COFFEE_TABLE_WIDTH = 1100;
    public const int SOFA_DEPTH = 850;
    public const int SOFA_MAX_WIDTH = 2200;
    public const int RUG_MAX_WIDTH = 2400;
    /** a lounge with a sofa needs this much room, else two armchairs */
    public const int LOUNGE_MIN_WIDTH = 3800;
    public const int LOUNGE_MIN_DEPTH = 2800;
    /** wide enough for an armchair either side of the sofa */
    public const int LOUNGE_ARMCHAIRS_WIDTH = 4600;

    private function __construct()
    {
    }

    /** A shrub on the lawn (always shown, it is part of the garden). */
    public static function bush(string $label, int $variant, float $height, float $x, float $z, float $turn = 0.0, float $y = 0.0): Part
    {
        return new Part(
            null,
            $label,
            Model::ofHeight(GardenModels::BUSHES, GardenModels::bush($variant), $height),
            position: new Vector3($x, $y, $z),
            rotation: new Vector3(0, $turn, 0),
        );
    }

    /** A plant in a terracotta pot (GardenModels::PLANT_*). */
    public static function pottedPlant(string $label, string $variant, float $height, float $x, float $z, float $turn = 0.0, float $y = 0.0): Part
    {
        return new Part(
            null,
            $label,
            Model::ofHeight(GardenModels::PLANTS, $variant, $height),
            position: new Vector3($x, $y, $z),
            rotation: new Vector3(0, $turn, 0),
            decor: true,
        );
    }

    /** A piece of garden furniture (GardenModels::LOUNGE_SOFA, FIRE_PIT, …), scaled to its width. */
    public static function furniture(string $label, string $variant, float $width, float $x, float $z, float $turn = 0.0, float $y = 0.0): Part
    {
        return new Part(
            null,
            $label,
            new Model(GardenModels::FURNITURE, $variant, width: $width),
            position: new Vector3($x, $y, $z),
            rotation: new Vector3(0, $turn, 0),
            decor: true,
        );
    }

    /**
     * A lounge corner sized to the room it gets: a sofa against the back
     * (z = 0) with rug, coffee table, side table and, if wide enough, an
     * armchair either side; in a small space two armchairs and a side table.
     *
     * @param float $width room across
     * @param float $depth room towards the front
     * @param float $y     the floor it stands on
     *
     * @return list<Part>
     */
    public static function lounge(string $label, float $width, float $depth, float $y = 0.0): array
    {
        if ($width < self::LOUNGE_MIN_WIDTH || $depth < self::LOUNGE_MIN_DEPTH) {
            $chairZ = 600;

            return [
                self::furniture($label, GardenModels::LOUNGE_ARMCHAIR, self::ARMCHAIR_WIDTH, -550, $chairZ, 0.25, $y),
                self::furniture($label, GardenModels::LOUNGE_ARMCHAIR, self::ARMCHAIR_WIDTH, 550, $chairZ, -0.25, $y),
                self::furniture($label, GardenModels::SIDE_TABLE, self::SIDE_TABLE_WIDTH, 0, $chairZ + 250, 0.0, $y),
            ];
        }
        $sofaWidth = min(self::SOFA_MAX_WIDTH, $width - 2200);
        $sofaZ = self::SOFA_DEPTH / 2;
        $tableZ = $sofaZ + self::SOFA_DEPTH / 2 + 650;
        $parts = [
            self::furniture($label, GardenModels::RUG, min(self::RUG_MAX_WIDTH, $width - 1200), 0, $tableZ - 100, 0.0, $y),
            self::furniture($label, GardenModels::LOUNGE_SOFA, $sofaWidth, 0, $sofaZ, 0.0, $y),
            self::furniture($label, GardenModels::COFFEE_TABLE, self::COFFEE_TABLE_WIDTH, 0, $tableZ, 0.0, $y),
            self::furniture($label, GardenModels::SIDE_TABLE, self::SIDE_TABLE_WIDTH, $sofaWidth / 2 + 350, $sofaZ - 100, 0.0, $y),
        ];
        if ($width >= self::LOUNGE_ARMCHAIRS_WIDTH) {
            $armchairX = $sofaWidth / 2 + 650;
            $parts[] = self::furniture($label, GardenModels::LOUNGE_ARMCHAIR, self::ARMCHAIR_WIDTH, -$armchairX, $tableZ + 250, M_PI / 2 - 0.35, $y);
            $parts[] = self::furniture($label, GardenModels::LOUNGE_ARMCHAIR, self::ARMCHAIR_WIDTH, $armchairX, $tableZ + 250, -M_PI / 2 + 0.35, $y);
        }

        return $parts;
    }

    /**
     * A fire pit on its gravel ring (centred on the origin) with two
     * armchairs drawn up to it from behind.
     *
     * @return list<Part>
     */
    public static function firePitCircle(string $label, float $ring, float $y = 0.0): array
    {
        return [
            self::furniture($label, GardenModels::FIRE_PIT, $ring, 0, 0, 0.0, $y),
            self::furniture($label, GardenModels::LOUNGE_ARMCHAIR, self::ARMCHAIR_WIDTH, -1500, -700, M_PI / 2 - 0.6, $y),
            self::furniture($label, GardenModels::LOUNGE_ARMCHAIR, self::ARMCHAIR_WIDTH, 1400, -900, -M_PI / 2 + 0.7, $y),
        ];
    }
}
