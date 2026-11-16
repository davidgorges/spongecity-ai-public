<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo;

/**
 * Latitude/longitude (ETRS89, as good as WGS 84 here) to UTM easting/northing
 * (Transverse Mercator, Krüger series to the fourth order: millimetre accuracy
 * within a zone). Hamburg's geodata is in zone 32 (EPSG:25832).
 */
final class Utm
{
    public const int ZONE_HAMBURG = 32;

    private const float A = 6_378_137.0;
    private const float F = 1 / 298.257222101;
    private const float K0 = 0.9996;
    private const float FALSE_EASTING = 500_000.0;

    private function __construct()
    {
    }

    /**
     * @return array{0: float, 1: float} [easting, northing] in metres
     */
    public static function fromGeo(float $latitude, float $longitude, int $zone = self::ZONE_HAMBURG): array
    {
        $n = self::F / (2 - self::F);
        $radius = self::A / (1 + $n) * (1 + $n ** 2 / 4 + $n ** 4 / 64);
        $alpha = [
            $n / 2 - 2 * $n ** 2 / 3 + 5 * $n ** 3 / 16 + 41 * $n ** 4 / 180,
            13 * $n ** 2 / 48 - 3 * $n ** 3 / 5 + 557 * $n ** 4 / 1440,
            61 * $n ** 3 / 240 - 103 * $n ** 4 / 140,
            49561 * $n ** 4 / 161280,
        ];
        $phi = deg2rad($latitude);
        $lambda = deg2rad($longitude - (6 * $zone - 183));
        $e = 2 * sqrt($n) / (1 + $n);
        $t = sinh(atanh(sin($phi)) - $e * atanh($e * sin($phi)));
        $xi = atan2($t, cos($lambda));
        $eta = atanh(sin($lambda) / sqrt(1 + $t * $t));
        $x = $eta;
        $y = $xi;
        foreach ($alpha as $j => $a) {
            $k = 2 * ($j + 1);
            $x += $a * cos($k * $xi) * sinh($k * $eta);
            $y += $a * sin($k * $xi) * cosh($k * $eta);
        }

        return [self::FALSE_EASTING + self::K0 * $radius * $x, self::K0 * $radius * $y];
    }
}
