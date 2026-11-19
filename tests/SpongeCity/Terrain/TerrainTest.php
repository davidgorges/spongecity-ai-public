<?php

declare(strict_types=1);

namespace App\Tests\SpongeCity\Terrain;

use App\SpongeCity\Geo\Hamburg\ElevationTile;
use App\SpongeCity\Geo\Hamburg\HamburgElevation;
use App\SpongeCity\Geo\Hamburg\HamburgTerrain;
use App\SpongeCity\Geo\Utm;
use App\SpongeCity\Terrain\SiteGrounding;
use App\SpongeCity\Terrain\Terrain;
use App\SpongeCity\Terrain\TerrainAnalysis;
use App\SpongeCity\Terrain\TerrainGrid;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteBuilder;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TerrainTest extends TestCase
{
    #[Test]
    public function projectsToUtmZone32(): void
    {
        // reference values from PROJ (cs2cs EPSG:4258 → EPSG:25832)
        foreach ([[53.5513228, 10.0187849, 567495.8772, 5934086.2162], [53.7, 10.3, 585823.7160, 5950929.0975]] as [$lat, $lon, $east, $north]) {
            [$e, $n] = Utm::fromGeo($lat, $lon);
            self::assertEqualsWithDelta($east, $e, 0.001);
            self::assertEqualsWithDelta($north, $n, 0.001);
        }
        // the false easting at the central meridian (9° E)
        self::assertEqualsWithDelta(500_000.0, Utm::fromGeo(53.5, 9.0)[0], 0.001);
    }

    #[Test]
    public function readsAnLzwCompressedFloatTiff(): void
    {
        $heights = [];
        for ($row = 0; $row < 4; ++$row) {
            for ($col = 0; $col < 4; ++$col) {
                $heights[] = 5.0 + $col + 10 * $row;
            }
        }
        $tile = ElevationTile::fromTiff(self::tiff(4, 4, $heights, 566000.0, 5935000.0, lzw: true));

        // pixel centres: the first at half a metre in from the corner
        self::assertEqualsWithDelta(5.0, $tile->heightAt(566000.5, 5934999.5), 1e-6);
        self::assertEqualsWithDelta(8.0, $tile->heightAt(566003.5, 5934999.5), 1e-6);
        self::assertEqualsWithDelta(35.0, $tile->heightAt(566000.5, 5934996.5), 1e-6);
        // between pixels: bilinear
        self::assertEqualsWithDelta(5.5 + 5, $tile->heightAt(566001.0, 5934999.0), 1e-6);
        self::assertNull($tile->heightAt(565999.0, 5934999.5), 'outside the tile');
    }

    #[Test]
    public function readsATileOutOfTheZipByByteRanges(): void
    {
        $tiff = self::tiff(2, 2, [1.0, 2.0, 3.0, 4.0], 567000.0, 5935000.0);
        $path = tempnam(sys_get_temp_dir(), 'dgm');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('dgm1_hh_2022/dgm1_hh_2022-04-30.csv', 'x');
        $zip->addFromString('dgm1_hh_2022/s32_567/dgm1_32_567_5934_1_hh_2022.tif', $tiff);
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);
        $ranges = 0;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($bytes, &$ranges): MockResponse {
            if ('HEAD' === $method) {
                return new MockResponse('', ['response_headers' => ['content-length' => (string) strlen($bytes)]]);
            }
            $header = implode("\n", $options['headers'] ?? []);
            preg_match('/Range: bytes=(\d+)-(\d+)/i', $header, $m);
            ++$ranges;

            return new MockResponse(substr($bytes, (int) $m[1], (int) $m[2] - (int) $m[1] + 1), ['http_code' => 206]);
        });

        $tiles = (new HamburgElevation($client, new ArrayAdapter()))->tiles(567100, 5934100, 567900, 5934900);

        self::assertSame(['567_5934'], array_keys($tiles));
        self::assertEqualsWithDelta(1.0, $tiles['567_5934']->heightAt(567000.5, 5934999.5), 1e-6);
        self::assertSame(4, $ranges, 'the end of the ZIP, its directory, then the tile\'s header and its data');
    }

    #[Test]
    public function standsUprightObjectsOnTheLowestGroundUnderThem(): void
    {
        // the ground rises 10 cm per metre to the east
        $terrain = self::slope(0.1);
        $site = SiteType::encode([
            [O::TYPE => O::BUILDING, O::POINTS => [[10000, -5000], [20000, -5000], [20000, 5000], [10000, 5000]], O::HEIGHT => 9000],
            [O::TYPE => O::TREE, O::X => -30000, O::Z => 0],
            [O::TYPE => O::PLOT, O::POINTS => [[-40000, -40000], [40000, -40000], [40000, 40000], [-40000, 40000]]],
        ]);

        $objects = SiteType::rawObjects((new SiteType())->normalize((new SiteGrounding())->ground($site, $terrain)));

        self::assertSame(1000, $objects[0][O::GROUND], 'its west wall, 10 m east: 1 m up');
        self::assertSame(-3000, $objects[1][O::GROUND]);
        self::assertSame(0, $objects[2][O::GROUND], 'the plot is draped by the view, not raised');

        $parts = (new SiteBuilder())->build(Site::fromJson((new SiteType())->normalize((new SiteGrounding())->ground($site, $terrain))))->parts();
        $walls = array_values(array_filter($parts, static fn ($p): bool => !$p->draped && $p->position->y > 0))[0];
        self::assertEqualsWithDelta(1000 + 4500, $walls->position->y, 1, 'the walls stand on the ground');
        self::assertFalse((new SiteGrounding())->ground($site, null) !== SiteType::encode(array_map(static fn (array $o): array => [...$o, O::GROUND => 0], SiteType::rawObjects($site))), 'without terrain everything stands at 0');
    }

    #[Test]
    public function interpolatesOnTheSameTrianglesAsTheView(): void
    {
        // one cell: corners 0 (NW), 1 (NE), 0 (SW), 0 (SE); split NW–SE, so the north-east half rises
        $grid = new TerrainGrid(0.0, 0.0, 2.0, 2, 2, [0, 100, 0, 0]);

        self::assertEqualsWithDelta(0.5, $grid->heightAt(1.5, 0.5), 1e-9, 'north-east of the diagonal');
        self::assertEqualsWithDelta(0.0, $grid->heightAt(0.5, 1.5), 1e-9, 'south-west of it: flat');
    }

    #[Test]
    public function findsWhereWaterRunsAndCollects(): void
    {
        // a plot falling to the south (z grows southwards) with a hollow at (10, 10)
        $terrain = self::field(static fn (float $x, float $z): float => -0.05 * $z - (hypot($x - 10, $z - 10) < 5 ? 0.3 * (1 - hypot($x - 10, $z - 10) / 5) : 0.0));
        $plot = [[-30000, -30000], [30000, -30000], [30000, 30000], [-30000, 30000]];

        $analysis = (new TerrainAnalysis())->ofPlot($terrain, $plot);

        self::assertNotNull($analysis);
        self::assertSame('south', $analysis['surface_water_runs_towards']);
        self::assertEqualsWithDelta(5.0, $analysis['mean_slope_percent'], 1.0);
        self::assertSame(['x' => 10000, 'z' => 10000], array_intersect_key($analysis['low_spots'][0], ['x' => 0, 'z' => 0]));
        self::assertSame(TerrainAnalysis::KIND_HOLLOW, $analysis['low_spots'][0]['kind']);
    }

    private static function slope(float $perMetre): Terrain
    {
        return self::field(static fn (float $x): float => $perMetre * $x);
    }

    /**
     * @param callable(float, float): float $height metres at plan metres
     */
    private static function field(callable $height): Terrain
    {
        $grid = static function (float $half, float $spacing) use ($height): TerrainGrid {
            $n = (int) round(2 * $half / $spacing) + 1;
            $heights = [];
            for ($r = 0; $r < $n; ++$r) {
                for ($c = 0; $c < $n; ++$c) {
                    $heights[] = (int) round($height(-$half + $c * $spacing, -$half + $r * $spacing) * 100);
                }
            }

            return new TerrainGrid(-$half, -$half, $spacing, $n, $n, $heights);
        };

        return new Terrain(5.0, $grid(60, 2), $grid(120, 4), HamburgTerrain::source());
    }

    /**
     * A minimal float32 GeoTIFF (little-endian, one strip).
     *
     * @param list<float> $heights row by row from the north
     */
    private static function tiff(int $width, int $height, array $heights, float $west, float $north, bool $lzw = false): string
    {
        $raster = pack('g*', ...$heights);
        $strip = $lzw ? self::lzw($raster) : $raster;
        $tags = [
            [256, 3, 1, $width], [257, 3, 1, $height], [258, 3, 1, 32], [259, 3, 1, $lzw ? 5 : 1], [262, 3, 1, 1],
            [273, 4, 1, 0], [277, 3, 1, 1], [278, 3, 1, $height], [279, 4, 1, strlen($strip)], [339, 3, 1, 3],
            [33550, 12, 3, [1.0, 1.0, 0.0]], [33922, 12, 6, [0.0, 0.0, 0.0, $west, $north, 0.0]],
        ];
        $directory = 8;
        $extra = $directory + 2 + count($tags) * 12 + 4;
        $blobs = '';
        $entries = '';
        foreach ($tags as [$tag, $type, $count, $value]) {
            if (12 === $type) {
                $entries .= pack('vvVV', $tag, $type, $count, $extra + strlen($blobs));
                $blobs .= pack('e*', ...$value);
            } else {
                $entries .= 3 === $type ? pack('vvVvv', $tag, $type, $count, $value, 0) : pack('vvVV', $tag, $type, $count, $value);
            }
        }
        $stripOffset = $extra + strlen($blobs);
        $entries = str_replace(pack('vvVV', 273, 4, 1, 0), pack('vvVV', 273, 4, 1, $stripOffset), $entries);

        return 'II'.pack('vV', 42, $directory).pack('v', count($tags)).$entries.pack('V', 0).$blobs.$strip;
    }

    /** TIFF LZW, the encoder to ElevationTile's decoder */
    private static function lzw(string $data): string
    {
        $table = [];
        for ($i = 0; $i < 256; ++$i) {
            $table[chr($i)] = $i;
        }
        [$next, $width, $bits, $buffer, $out] = [258, 9, 0, 0, ''];
        $emit = static function (int $code) use (&$width, &$bits, &$buffer, &$out): void {
            $buffer = ($buffer << $width) | $code;
            $bits += $width;
            while ($bits >= 8) {
                $out .= chr(($buffer >> ($bits - 8)) & 0xFF);
                $bits -= 8;
            }
            $buffer &= (1 << $bits) - 1;
        };
        $emit(256);
        $w = '';
        for ($i = 0, $n = strlen($data); $i < $n; ++$i) {
            $wc = $w.$data[$i];
            if (isset($table[$wc])) {
                $w = $wc;
                continue;
            }
            $emit($table[$w]);
            $table[$wc] = $next++;
            if ($next >= (1 << $width) - 1 && $width < 12) {
                ++$width;
            }
            $w = $data[$i];
        }
        $emit($table[$w]);
        $emit(257);
        if ($bits > 0) {
            $out .= chr(($buffer << (8 - $bits)) & 0xFF);
        }

        return $out;
    }
}
