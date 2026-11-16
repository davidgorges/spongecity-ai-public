<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo\Hamburg;

/**
 * One tile of Hamburg's terrain model (DGM1): a 1 × 1 km GeoTIFF of float32 heights
 * above sea level (DHHN2016) every metre, in UTM 32 (EPSG:25832). Read from the
 * file itself: one strip, LZW or uncompressed, little- or big-endian, the origin
 * from the GeoTIFF tie point and pixel scale.
 *
 * Holds the decoded raster as a binary string and reads samples on demand, so a
 * tile costs 4 MB rather than an array of a million floats.
 */
final readonly class ElevationTile
{
    private const int TAG_WIDTH = 256;
    private const int TAG_HEIGHT = 257;
    private const int TAG_BITS = 258;
    private const int TAG_COMPRESSION = 259;
    private const int TAG_STRIP_OFFSETS = 273;
    private const int TAG_STRIP_BYTES = 279;
    private const int TAG_PREDICTOR = 317;
    private const int TAG_SAMPLE_FORMAT = 339;
    private const int TAG_PIXEL_SCALE = 33550;
    private const int TAG_TIE_POINT = 33922;

    private const int COMPRESSION_NONE = 1;
    private const int COMPRESSION_LZW = 5;
    private const int SAMPLE_FLOAT = 3;
    private const int PREDICTOR_NONE = 1;
    /** heights below this are "no data" (the file's nodata is −3.4e38) */
    private const float NO_DATA_BELOW = -1000.0;

    private function __construct(
        /** easting of the left edge, northing of the top edge, metres per pixel */
        public float $west,
        public float $north,
        public float $pixel,
        public int $width,
        public int $height,
        private string $raster,
        private bool $littleEndian,
    ) {
    }

    public static function fromTiff(string $tiff): self
    {
        $little = 'II' === substr($tiff, 0, 2);
        $u16 = static fn (int $at): int => unpack($little ? 'v' : 'n', $tiff, $at)[1];
        $u32 = static fn (int $at): int => unpack($little ? 'V' : 'N', $tiff, $at)[1];
        $f64 = static fn (int $at): float => unpack($little ? 'e' : 'E', $tiff, $at)[1];

        $directory = $u32(4);
        $tags = [];
        for ($i = 0, $count = $u16($directory); $i < $count; ++$i) {
            $entry = $directory + 2 + $i * 12;
            [$tag, $type, $n] = [$u16($entry), $u16($entry + 2), $u32($entry + 4)];
            $size = [1 => 1, 2 => 1, 3 => 2, 4 => 4, 12 => 8][$type] ?? 1;
            $at = $size * $n <= 4 ? $entry + 8 : $u32($entry + 8);
            $tags[$tag] = match ($type) {
                3 => array_map(static fn (int $k): int => $u16($at + 2 * $k), range(0, $n - 1)),
                4 => array_map(static fn (int $k): int => $u32($at + 4 * $k), range(0, $n - 1)),
                12 => array_map(static fn (int $k): float => $f64($at + 8 * $k), range(0, $n - 1)),
                default => [],
            };
        }
        $first = static fn (int $tag, int|float $default = 0): int|float => $tags[$tag][0] ?? $default;
        if (32 !== $first(self::TAG_BITS) || self::SAMPLE_FLOAT !== $first(self::TAG_SAMPLE_FORMAT) || self::PREDICTOR_NONE !== $first(self::TAG_PREDICTOR, self::PREDICTOR_NONE)) {
            throw new \UnexpectedValueException('Not a float32 elevation raster without predictor.');
        }
        $compression = $first(self::TAG_COMPRESSION, self::COMPRESSION_NONE);
        $raster = '';
        foreach ($tags[self::TAG_STRIP_OFFSETS] ?? [] as $k => $offset) {
            $strip = substr($tiff, $offset, $tags[self::TAG_STRIP_BYTES][$k] ?? 0);
            $raster .= match ($compression) {
                self::COMPRESSION_NONE => $strip,
                self::COMPRESSION_LZW => self::lzw($strip),
                default => throw new \UnexpectedValueException(sprintf('Unsupported TIFF compression %d.', $compression)),
            };
        }
        [$width, $height] = [(int) $first(self::TAG_WIDTH), (int) $first(self::TAG_HEIGHT)];
        if (strlen($raster) < $width * $height * 4) {
            throw new \UnexpectedValueException('The elevation raster is shorter than its size.');
        }
        $tie = $tags[self::TAG_TIE_POINT] ?? [0.0, 0.0, 0.0, 0.0, 0.0, 0.0];

        return new self((float) $tie[3], (float) $tie[4], (float) $first(self::TAG_PIXEL_SCALE, 1.0), $width, $height, $raster, $little);
    }

    /**
     * Height at a point (bilinear between the pixel centres), null outside or without data.
     */
    public function heightAt(float $easting, float $northing): ?float
    {
        // pixel centres sit half a pixel inside the edges
        $u = ($easting - $this->west) / $this->pixel - 0.5;
        $v = ($this->north - $northing) / $this->pixel - 0.5;
        $u = max(0.0, min($this->width - 1.0, $u));
        $v = max(0.0, min($this->height - 1.0, $v));
        if ($easting < $this->west || $easting > $this->west + $this->width * $this->pixel || $northing > $this->north || $northing < $this->north - $this->height * $this->pixel) {
            return null;
        }
        [$c, $r] = [(int) floor($u), (int) floor($v)];
        [$c1, $r1] = [min($c + 1, $this->width - 1), min($r + 1, $this->height - 1)];
        [$fu, $fv] = [$u - $c, $v - $r];
        $sum = 0.0;
        $weights = 0.0;
        foreach ([[$c, $r, (1 - $fu) * (1 - $fv)], [$c1, $r, $fu * (1 - $fv)], [$c, $r1, (1 - $fu) * $fv], [$c1, $r1, $fu * $fv]] as [$col, $row, $w]) {
            $h = $this->sample($col, $row);
            if (null !== $h && $w > 0) {
                $sum += $h * $w;
                $weights += $w;
            }
        }

        return $weights > 0 ? $sum / $weights : null;
    }

    private function sample(int $col, int $row): ?float
    {
        $value = unpack($this->littleEndian ? 'g' : 'G', $this->raster, ($row * $this->width + $col) * 4)[1];

        return $value > self::NO_DATA_BELOW ? (float) $value : null;
    }

    /**
     * TIFF LZW: codes MSB first, 9 to 12 bits, 256 clears, 257 ends; the code width grows
     * one code early (when the next code would be 2^width − 1).
     */
    private static function lzw(string $data): string
    {
        // one growing string: an array of a million short entries would cost ten times the memory
        $out = '';
        $table = [];
        $reset = static function () use (&$table): void {
            $table = [];
            for ($i = 0; $i < 256; ++$i) {
                $table[$i] = chr($i);
            }
        };
        $reset();
        [$next, $width, $previous] = [258, 9, null];
        [$buffer, $bits, $position, $length] = [0, 0, 0, strlen($data)];
        while (true) {
            while ($bits < $width) {
                if ($position >= $length) {
                    break 2;
                }
                $buffer = (($buffer << 8) | ord($data[$position++])) & 0xFFFFFF;
                $bits += 8;
            }
            $code = ($buffer >> ($bits - $width)) & ((1 << $width) - 1);
            $bits -= $width;
            if (256 === $code) {
                $reset();
                [$next, $width, $previous] = [258, 9, null];
                continue;
            }
            if (257 === $code) {
                break;
            }
            if (null === $previous) {
                $entry = $table[$code];
                $out .= $entry;
                $previous = $entry;
                continue;
            }
            $entry = $table[$code] ?? $previous.$previous[0];
            $out .= $entry;
            $table[$next++] = $previous.$entry[0];
            $previous = $entry;
            if ($next >= (1 << $width) - 1 && $width < 12) {
                ++$width;
            }
        }

        return $out;
    }
}
