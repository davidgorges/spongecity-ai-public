<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo;

/**
 * The pool for what the geodata services answered about a place: the geocoded address, the
 * OpenStreetMap elements, Hamburg's buildings, infiltration areas and LOD3 roofs around a site.
 * The same address asks the same questions, so its import reuses these answers instead of asking
 * the (often overloaded) public services again.
 *
 * Redis in production, shared by the web server and the workers and kept across deploys
 * (config/packages/cache.yaml); the application cache on a developer machine and in tests
 * (config/services.yaml). The large raw downloads (zip archives, 3D tiles, see RemoteZip) stay in
 * the application cache on disk: Redis keeps everything in memory.
 */
final class GeodataCache
{
    public const string POOL = 'spongecity.geodata';

    private function __construct()
    {
    }
}
