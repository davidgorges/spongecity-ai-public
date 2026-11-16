<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo\Hamburg;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Hamburg's terrain model DGM1 (1 m, airborne laser scan 2022), read tile by tile
 * out of the city's 1.4 GB ZIP download with HTTP range requests: the ZIP's central
 * directory tells where each 1 × 1 km GeoTIFF lies, then only that tile's bytes
 * (about 1.7 MB) are fetched and inflated. Directory and tiles are cached for a week.
 */
final readonly class HamburgElevation
{
    public const string ZIP_URL = 'https://archiv.transparenz.hamburg.de/hmbtgarchive/HMDK/dgm1_hh_2022-04-30_258334_snap_1.zip';
    public const string AS_OF = '2022';
    /** tiles are named by the kilometre of their south-west corner: dgm1_32_<E km>_<N km>_1_hh_2022.tif */
    private const string TILE_NAME = '_32_%d_%d_1_hh_2022.tif';
    private const int TILE_METRES = 1000;

    private const int END_OF_DIRECTORY_SEARCH = 65_557;
    private const string END_OF_DIRECTORY = "PK\x05\x06";
    private const string DIRECTORY_ENTRY = "PK\x01\x02";
    private const int LOCAL_HEADER = 30;
    private const int METHOD_DEFLATE = 8;
    private const int METHOD_STORED = 0;

    private const int CACHE_SECONDS = 604_800;
    private const int CACHE_VERSION = 1;
    private const int TIMEOUT_SECONDS = 30;
    private const int MAX_DURATION_SECONDS = 120;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        #[Autowire(env: 'OSM_USER_AGENT')]
        private string $userAgent,
    ) {
    }

    /**
     * The tiles covering a rectangle in UTM 32, by "E_N" kilometre; tiles the model
     * does not have (outside the city, in the river) are left out.
     *
     * @return array<string, ElevationTile>
     */
    public function tiles(float $west, float $south, float $east, float $north): array
    {
        $tiles = [];
        for ($e = (int) floor($west / self::TILE_METRES); $e <= (int) floor($east / self::TILE_METRES); ++$e) {
            for ($n = (int) floor($south / self::TILE_METRES); $n <= (int) floor($north / self::TILE_METRES); ++$n) {
                $tiff = $this->tiff($e, $n);
                if (null !== $tiff) {
                    $tiles[$e.'_'.$n] = ElevationTile::fromTiff($tiff);
                }
            }
        }

        return $tiles;
    }

    private function tiff(int $eastKm, int $northKm): ?string
    {
        $entry = $this->directory()[sprintf(self::TILE_NAME, $eastKm, $northKm)] ?? null;
        if (null === $entry) {
            return null;
        }

        return $this->cache->get(sprintf('dgm1_v%d_%d_%d', self::CACHE_VERSION, $eastKm, $northKm), function (ItemInterface $item) use ($entry): string {
            $item->expiresAfter(self::CACHE_SECONDS);
            [$offset, $compressed, $method] = $entry;
            $header = $this->range($offset, self::LOCAL_HEADER);
            $skip = unpack('v', $header, 26)[1] + unpack('v', $header, 28)[1];
            $data = $this->range($offset + self::LOCAL_HEADER + $skip, $compressed);
            $tiff = match ($method) {
                self::METHOD_DEFLATE => gzinflate($data),
                self::METHOD_STORED => $data,
                default => false,
            };
            if (false === $tiff) {
                throw new \UnexpectedValueException('A terrain tile could not be unpacked.');
            }

            return $tiff;
        });
    }

    /**
     * The tiles of the ZIP: name suffix → [local header offset, compressed size, method].
     *
     * @return array<string, array{int, int, int}>
     */
    private function directory(): array
    {
        return $this->cache->get(sprintf('dgm1_directory_v%d', self::CACHE_VERSION), function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_SECONDS);
            $size = (int) ($this->httpClient->request('HEAD', self::ZIP_URL, $this->options())->getHeaders()['content-length'][0] ?? 0);
            $tail = $this->range(max(0, $size - self::END_OF_DIRECTORY_SEARCH), min($size, self::END_OF_DIRECTORY_SEARCH));
            $end = strrpos($tail, self::END_OF_DIRECTORY);
            if (false === $end) {
                throw new \UnexpectedValueException('The terrain ZIP has no central directory.');
            }
            [$length, $start] = [unpack('V', $tail, $end + 12)[1], unpack('V', $tail, $end + 16)[1]];
            $directory = $this->range($start, $length);
            $entries = [];
            for ($p = 0; self::DIRECTORY_ENTRY === substr($directory, $p, 4);) {
                $method = unpack('v', $directory, $p + 10)[1];
                $compressed = unpack('V', $directory, $p + 20)[1];
                [$nameLength, $extra, $comment] = array_values(unpack('v3', $directory, $p + 28));
                $offset = unpack('V', $directory, $p + 42)[1];
                $name = substr($directory, $p + 46, $nameLength);
                if (1 === preg_match('/_32_\d+_\d+_1_hh_2022\.tif$/i', $name, $match)) {
                    $entries[strtolower($match[0])] = [$offset, $compressed, $method];
                }
                $p += 46 + $nameLength + $extra + $comment;
            }

            return $entries;
        });
    }

    private function range(int $offset, int $length): string
    {
        $options = $this->options();
        $options['headers']['Range'] = sprintf('bytes=%d-%d', $offset, $offset + $length - 1);
        $body = $this->httpClient->request('GET', self::ZIP_URL, $options)->getContent();
        if (strlen($body) !== $length) {
            throw new \UnexpectedValueException('The terrain server did not answer the byte range.');
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return ['headers' => ['User-Agent' => $this->userAgent], 'timeout' => self::TIMEOUT_SECONDS, 'max_duration' => self::MAX_DURATION_SECONDS];
    }
}
