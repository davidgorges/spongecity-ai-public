<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo\Hamburg;

use App\SpongeCity\Geo\RemoteZip;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Hamburg's terrain model DGM1 (1 m, airborne laser scan 2022), read tile by tile
 * out of the city's 1.4 GB ZIP download (RemoteZip): only the 1 × 1 km GeoTIFFs a
 * site needs, about 1.7 MB each, cached (GeodataCache::LIFETIME_SECONDS).
 */
final readonly class HamburgElevation
{
    public const string ZIP_URL = 'https://archiv.transparenz.hamburg.de/hmbtgarchive/HMDK/dgm1_hh_2022-04-30_258334_snap_1.zip';
    public const string AS_OF = '2022';
    /** tiles are named by the kilometre of their south-west corner: …/dgm1_32_<E km>_<N km>_1_hh_2022.tif */
    private const string TILE_PATTERN = '#_32_%d_%d_1_hh_2022\.tif$#i';
    private const int TILE_METRES = 1000;

    private RemoteZip $zip;

    public function __construct(
        HttpClientInterface $httpClient,
        CacheInterface $cache,
    ) {
        $this->zip = new RemoteZip($httpClient, $cache, self::ZIP_URL);
    }

    /**
     * The tiles covering a rectangle in UTM 32, by "E_N" kilometre; tiles the model
     * does not have (outside the city) are left out.
     *
     * @return array<string, ElevationTile>
     */
    public function tiles(float $west, float $south, float $east, float $north): array
    {
        $tiles = [];
        for ($e = (int) floor($west / self::TILE_METRES); $e <= (int) floor($east / self::TILE_METRES); ++$e) {
            for ($n = (int) floor($south / self::TILE_METRES); $n <= (int) floor($north / self::TILE_METRES); ++$n) {
                $name = $this->zip->find(sprintf(self::TILE_PATTERN, $e, $n));
                if (null !== $name) {
                    $tiles[$e.'_'.$n] = ElevationTile::fromTiff($this->zip->read($name));
                }
            }
        }

        return $tiles;
    }
}
