<?php

declare(strict_types=1);

namespace Core\Configurator\Scenery;

use Core\Configurator\Assembly\Box;
use Core\Configurator\Assembly\Part;
use Core\Configurator\Assembly\Vector3;
use Webmozart\Assert\Assert;

/**
 * A building described by its dimensions (a customer's house, a garage, a
 * shed): walls, windows on every façade, a door, and a gable, hip,
 * monopitch or flat roof. Coarser than House, but any size and shape of
 * roof; the site model builds what an agent or the customer describes.
 *
 * Its own frame: x along the front façade (centred), the front façade at
 * z = 0 facing +z, the building behind it down to z = -depth, y up. Place
 * it with Part::placedAt().
 */
final readonly class Building
{
    public const string ROOF_GABLE = 'gable';
    public const string ROOF_HIP = 'hip';
    public const string ROOF_MONOPITCH = 'monopitch';
    public const string ROOF_FLAT = 'flat';
    public const array ROOFS = [self::ROOF_GABLE, self::ROOF_HIP, self::ROOF_MONOPITCH, self::ROOF_FLAT];

    public const string FACADE_FRONT = 'front';
    public const string FACADE_BACK = 'back';
    public const string FACADE_LEFT = 'left';
    public const string FACADE_RIGHT = 'right';
    public const array FACADES = [self::FACADE_FRONT, self::FACADE_BACK, self::FACADE_LEFT, self::FACADE_RIGHT];

    public const string DOOR_NONE = 'none';
    public const string DOOR_ENTRANCE = 'door';
    public const string DOOR_GARAGE = 'garage';

    public const int STOREY = 2750;
    private const int SILL = 900;
    private const int WINDOW_WIDTH = 1200;
    private const int WINDOW_HEIGHT = 1300;
    private const int WINDOW_SPACING = 2600;
    private const int WINDOW_MARGIN = 1000;
    private const int FRAME_DEPTH = 40;
    private const int FRAME = 70;
    private const int DOOR_WIDTH = 1100;
    private const int DOOR_HEIGHT = 2200;
    private const int GARAGE_DOOR_WIDTH = 2500;
    private const int GARAGE_DOOR_HEIGHT = 2150;
    private const int EAVES_OVERHANG = 400;
    private const int GABLE_OVERHANG = 300;
    private const int ROOF_THICKNESS = 180;
    private const int FLAT_ROOF = 250;
    private const int FLAT_OVERHANG = 150;
    private const int WALL = 300;
    /** below this pitch a roof is built flat */
    private const float MIN_PITCH = 3.0;

    /**
     * @param int    $storeys number of floors with windows (0: none, e.g. a garage)
     * @param string $door    DOOR_* on the $doorFacade, $doorOffset from its middle
     */
    public function __construct(
        private string $label,
        private float $width,
        private float $depth,
        private float $eavesHeight,
        private string $roof,
        private float $pitch,
        private bool $ridgeAlongFacade,
        private string $wallMaterial,
        private string $roofMaterial,
        private string $frameMaterial,
        private string $glassMaterial,
        private string $doorMaterial,
        private int $storeys = 0,
        private string $door = self::DOOR_NONE,
        private string $doorFacade = self::FACADE_FRONT,
        private float $doorOffset = 0,
    ) {
        Assert::greaterThan($width, 0);
        Assert::greaterThan($depth, 0);
        Assert::oneOf($roof, self::ROOFS);
    }

    /**
     * @return list<Part>
     */
    public function parts(): array
    {
        $parts = [$this->box($this->wallMaterial, $this->width, $this->eavesHeight, $this->depth, 0, $this->eavesHeight / 2, -$this->depth / 2)];
        foreach (self::FACADES as $facade) {
            [$x, $z, $angle, $length] = $this->facadeFrame($facade);
            foreach ($this->openings($facade, $length) as $part) {
                $parts[] = $part->placedAt($x, $z, $angle);
            }
        }

        return [...$parts, ...$this->roofParts()];
    }

    /**
     * Where a façade's own frame (x along it, z out of it) lies in the
     * building's: its middle, the turn, its length.
     *
     * @return array{float, float, float, float}
     */
    private function facadeFrame(string $facade): array
    {
        return match ($facade) {
            self::FACADE_BACK => [0.0, -$this->depth, M_PI, $this->width],
            self::FACADE_LEFT => [-$this->width / 2, -$this->depth / 2, -M_PI / 2, $this->depth],
            self::FACADE_RIGHT => [$this->width / 2, -$this->depth / 2, M_PI / 2, $this->depth],
            default => [0.0, 0.0, 0.0, $this->width],
        };
    }

    /**
     * Windows in rows, one row per storey, evenly along the façade; the
     * door on the ground floor where windows would be in its way.
     *
     * @return list<Part> in the façade's frame
     */
    private function openings(string $facade, float $length): array
    {
        $parts = [];
        $door = null;
        if (self::DOOR_NONE !== $this->door && $facade === $this->doorFacade) {
            $door = $this->doorPart($length);
            $parts[] = $door;
        }
        $count = max(0, (int) floor(($length - self::WINDOW_MARGIN) / self::WINDOW_SPACING));
        for ($storey = 0; $storey < $this->storeys; ++$storey) {
            $sill = $storey * self::STOREY + self::SILL;
            if ($sill + self::WINDOW_HEIGHT > $this->eavesHeight - 200) {
                break;
            }
            for ($i = 0; $i < $count; ++$i) {
                $x = -$length / 2 + ($i + 0.5) * $length / $count;
                if (0 === $storey && null !== $door && abs($x - $door->position->x) < ($door->shape instanceof Box ? $door->shape->width : 0) / 2 + self::WINDOW_WIDTH / 2 + 200) {
                    continue;
                }
                $parts[] = $this->box($this->frameMaterial, self::WINDOW_WIDTH, self::WINDOW_HEIGHT, self::FRAME_DEPTH, $x, $sill + self::WINDOW_HEIGHT / 2, self::FRAME_DEPTH / 2);
                $parts[] = $this->box($this->glassMaterial, self::WINDOW_WIDTH - 2 * self::FRAME, self::WINDOW_HEIGHT - 2 * self::FRAME, 20, $x, $sill + self::WINDOW_HEIGHT / 2, self::FRAME_DEPTH + 10);
            }
        }

        return $parts;
    }

    private function doorPart(float $length): Part
    {
        $garage = self::DOOR_GARAGE === $this->door;
        $width = min($garage ? self::GARAGE_DOOR_WIDTH : self::DOOR_WIDTH, $length - 600);
        $height = min($garage ? self::GARAGE_DOOR_HEIGHT : self::DOOR_HEIGHT, $this->eavesHeight - 250);
        $limit = max(0, $length / 2 - $width / 2 - 300);
        $x = max(-$limit, min($limit, $this->doorOffset));

        return $this->box($this->doorMaterial, $width, $height, self::FRAME_DEPTH, $x, $height / 2, self::FRAME_DEPTH / 2);
    }

    /**
     * The roof, built with its ridge along x in a frame centred on the
     * building, then turned when the ridge runs front to back.
     *
     * @return list<Part>
     */
    private function roofParts(): array
    {
        $along = $this->ridgeAlongFacade;
        // a hip roof's ridge runs along the longer side
        if (self::ROOF_HIP === $this->roof) {
            $along = $this->width >= $this->depth;
        }
        [$a, $b] = $along ? [$this->width, $this->depth] : [$this->depth, $this->width];
        $pitch = deg2rad($this->pitch);
        $parts = match (true) {
            self::ROOF_FLAT === $this->roof || $this->pitch < self::MIN_PITCH => $this->flatRoof($a, $b),
            self::ROOF_HIP === $this->roof => $this->hipRoof($a, $b, $pitch),
            self::ROOF_MONOPITCH === $this->roof => $this->monopitchRoof($a, $b, $pitch),
            default => $this->gableRoof($a, $b, $pitch),
        };

        return array_map(fn (Part $part): Part => $part->placedAt(0, -$this->depth / 2, $along ? 0.0 : M_PI / 2), $parts);
    }

    /** @return list<Part> */
    private function flatRoof(float $a, float $b): array
    {
        return [$this->box($this->roofMaterial, $a + 2 * self::FLAT_OVERHANG, self::FLAT_ROOF, $b + 2 * self::FLAT_OVERHANG, 0, $this->eavesHeight + self::FLAT_ROOF / 2, 0)];
    }

    /** @return list<Part> two roof planes and the gable walls */
    private function gableRoof(float $a, float $b, float $pitch): array
    {
        $e = $this->eavesHeight;
        $o = self::EAVES_OVERHANG;
        $g = self::GABLE_OVERHANG;
        $top = $e + $b / 2 * tan($pitch);
        $low = $e - $o * tan($pitch);
        $parts = [];
        foreach ([1, -1] as $side) {
            $parts[] = $this->plane([[-$a / 2 - $g, $low, $side * ($b / 2 + $o)], [$a / 2 + $g, $low, $side * ($b / 2 + $o)], [$a / 2 + $g, $top, 0], [-$a / 2 - $g, $top, 0]]);
            $x = $side * ($a / 2 - self::WALL / 2);
            $parts[] = Slab::planar($this->label, $this->wallMaterial, [[$x, $e, $b / 2], [$x, $e, -$b / 2], [$x, $top, 0]], self::WALL, centred: true);
        }

        return array_values(array_filter($parts));
    }

    /** @return list<Part> two trapezoids and two triangles meeting at the ridge (a pyramid on a square) */
    private function hipRoof(float $a, float $b, float $pitch): array
    {
        $o = self::EAVES_OVERHANG;
        $top = $this->eavesHeight + $b / 2 * tan($pitch);
        $low = $this->eavesHeight - $o * tan($pitch);
        $r = ($a - $b) / 2;
        [$xa, $zb] = [$a / 2 + $o, $b / 2 + $o];

        return array_values(array_filter([
            $this->plane([[-$xa, $low, $zb], [$xa, $low, $zb], [$r, $top, 0], [-$r, $top, 0]]),
            $this->plane([[$xa, $low, -$zb], [-$xa, $low, -$zb], [-$r, $top, 0], [$r, $top, 0]]),
            $this->plane([[$xa, $low, $zb], [$xa, $low, -$zb], [$r, $top, 0]]),
            $this->plane([[-$xa, $low, -$zb], [-$xa, $low, $zb], [-$r, $top, 0]]),
        ]));
    }

    /** @return list<Part> one plane rising to the back, the walls closing the space below it */
    private function monopitchRoof(float $a, float $b, float $pitch): array
    {
        $e = $this->eavesHeight;
        $o = self::EAVES_OVERHANG;
        $g = self::GABLE_OVERHANG;
        $rise = $b * tan($pitch);
        $parts = [
            $this->plane([[-$a / 2 - $g, $e - $o * tan($pitch), $b / 2 + $o], [$a / 2 + $g, $e - $o * tan($pitch), $b / 2 + $o], [$a / 2 + $g, $e + $rise + $o * tan($pitch), -$b / 2 - $o], [-$a / 2 - $g, $e + $rise + $o * tan($pitch), -$b / 2 - $o]]),
            $this->box($this->wallMaterial, $a, $rise, self::WALL, 0, $e + $rise / 2, -$b / 2 + self::WALL / 2),
        ];
        foreach ([1, -1] as $side) {
            $x = $side * ($a / 2 - self::WALL / 2);
            $parts[] = Slab::planar($this->label, $this->wallMaterial, [[$x, $e, $b / 2], [$x, $e, -$b / 2], [$x, $e + $rise, -$b / 2]], self::WALL, centred: true);
        }

        return array_values(array_filter($parts));
    }

    /**
     * @param list<array{float, float, float}> $points
     */
    private function plane(array $points): ?Part
    {
        return Slab::planar($this->label, $this->roofMaterial, $points, self::ROOF_THICKNESS);
    }

    private function box(string $material, float $w, float $h, float $d, float $x, float $y, float $z): Part
    {
        return new Part(null, $this->label, new Box($w, $h, $d), $material, new Vector3($x, $y, $z));
    }
}
