<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo\Hamburg;

use App\SpongeCity\Geo\GeoBox;
use App\SpongeCity\Geo\GeoPoint;

/**
 * Where Hamburg's official geodata applies, and what all of it shares.
 */
final class Hamburg
{
    public const string LICENCE = 'Datenlizenz Deutschland – Namensnennung – Version 2.0 (dl-de/by-2-0)';
    public const string LGV = 'Freie und Hansestadt Hamburg, Landesbetrieb Geoinformation und Vermessung (LGV)';
    public const string BUKEA = 'Freie und Hansestadt Hamburg, Behörde für Umwelt, Klima, Energie und Agrarwirtschaft (BUKEA)';

    /** the city's extent (mainland and harbour), generously */
    private const float WEST = 9.70;
    private const float EAST = 10.34;
    private const float SOUTH = 53.38;
    private const float NORTH = 53.75;

    private function __construct()
    {
    }

    public static function covers(GeoPoint $point): bool
    {
        return (new GeoBox(self::WEST, self::SOUTH, self::EAST, self::NORTH))->contains($point);
    }
}
