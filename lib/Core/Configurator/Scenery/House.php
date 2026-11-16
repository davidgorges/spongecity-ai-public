<?php

declare(strict_types=1);

namespace Core\Configurator\Scenery;

use Core\Configurator\Assembly\Box;
use Core\Configurator\Assembly\Extrusion;
use Core\Configurator\Assembly\Part;
use Core\Configurator\Assembly\Vector3;
use Core\Configurator\Scene\Material;
use Core\Configurator\Scene\ProceduralTexture;
use Webmozart\Assert\Assert;

/**
 * Scenery: a two-storey house whose rendered façade (at z = 0, facing +z,
 * centred on x = 0) carries a terrace door and windows; it extends to -depth,
 * under a gable roof with tiles, fascias and a chimney. Not on the bill of
 * materials (parts have no SKU). Shared by all outdoor products, which add
 * materials() to their own library. Millimetres.
 */
final class House
{
    public const string MATERIAL_RENDER = 'house_render';
    public const string MATERIAL_PLINTH = 'house_plinth';
    public const string MATERIAL_FRAME = 'house_window_frame';
    public const string MATERIAL_GLASS = 'house_window_glass';
    public const string MATERIAL_SILL = 'house_sill';
    public const string MATERIAL_ROOF = 'house_roof';
    public const string MATERIAL_FASCIA = 'house_fascia';

    private const string LABEL = 'scenery.house';
    private const int WALL = 300;
    private const int PLINTH = 350;
    private const int FRAME = 70;
    private const int FRAME_DEPTH = 80;
    private const int GLASS_INSET = 120;
    private const int SILL_DEPTH = 60;
    private const int ROOF_THICKNESS = 180;
    private const int EAVES_OVERHANG = 450;
    private const int GABLE_OVERHANG = 300;
    private const int FASCIA = 220;
    private const float PITCH_DEGREES = 38.0;
    private const int GROUND_WINDOW_SILL = 900;
    private const int UPPER_FLOOR = 3000;
    private const int WINDOW_WIDTH = 1250;
    private const int WINDOW_HEIGHT = 1350;
    private const int DOOR_WIDTH = 2600;
    private const int DOOR_HEIGHT = 2250;
    private const int MIN_PIER = 600;

    /** @var list<array{x: float, y: float, width: float, height: float, door: bool}> */
    private array $openings = [];

    /**
     * @param float $width       façade width
     * @param float $eavesHeight height of the eaves above ground; the upper floor needs at least 5 m
     */
    public function __construct(
        private readonly float $width,
        private readonly float $depth = 9000,
        private readonly float $eavesHeight = 5800,
        private readonly float $offsetZ = 0,
    ) {
        Assert::greaterThanEq($width, 5000);
        Assert::greaterThanEq($eavesHeight, 5000);
        $this->planOpenings();
    }

    /**
     * @return array<string, Material>
     */
    public static function materials(): array
    {
        return [
            self::MATERIAL_RENDER => new Material('#f1e7d8', roughness: 0.95, texture: ProceduralTexture::Plaster, textureSize: 1.6),
            self::MATERIAL_PLINTH => new Material('#77736d', roughness: 0.9, texture: ProceduralTexture::Plaster, textureSize: 1.0),
            self::MATERIAL_FRAME => new Material('#2f3336', roughness: 0.45, clearcoat: 0.3),
            // a dark interior behind glass: reflections carry the look
            self::MATERIAL_GLASS => new Material('#4a565e', roughness: 0.02, metalness: 0.8, clearcoat: 1.0, texture: ProceduralTexture::Interior, textureSize: 2.6, nightGlow: '#ffb36b', nightGlowIntensity: 1.1),
            self::MATERIAL_SILL => new Material('#bdbab3', roughness: 0.6),
            self::MATERIAL_ROOF => new Material('#4a4644', roughness: 0.75, texture: ProceduralTexture::RoofTiles, textureSize: 1.2),
            self::MATERIAL_FASCIA => new Material('#f4f2ee', roughness: 0.55),
        ];
    }

    /**
     * @return list<Part>
     */
    public function parts(): array
    {
        return [
            ...$this->body(),
            ...$this->facade(),
            ...$this->windows(),
            ...$this->roof(),
        ];
    }

    /**
     * Terrace door in the middle, a window either side on the ground floor if
     * the façade is wide enough, windows above all of them.
     */
    private function planOpenings(): void
    {
        $this->openings[] = ['x' => 0, 'y' => 0, 'width' => self::DOOR_WIDTH, 'height' => self::DOOR_HEIGHT, 'door' => true];
        $upper = [-self::DOOR_WIDTH / 4 - 300, self::DOOR_WIDTH / 4 + 300];

        $sideX = $this->width / 2 - self::MIN_PIER * 2 - self::WINDOW_WIDTH / 2;
        if ($sideX - self::WINDOW_WIDTH / 2 > self::DOOR_WIDTH / 2 + self::MIN_PIER * 2) {
            foreach ([-$sideX, $sideX] as $x) {
                $this->openings[] = ['x' => $x, 'y' => self::GROUND_WINDOW_SILL, 'width' => self::WINDOW_WIDTH, 'height' => self::WINDOW_HEIGHT, 'door' => false];
                $upper[] = $x;
            }
        }
        foreach ($upper as $x) {
            $this->openings[] = ['x' => $x, 'y' => self::UPPER_FLOOR + self::GROUND_WINDOW_SILL - 200, 'width' => self::WINDOW_WIDTH, 'height' => self::WINDOW_HEIGHT, 'door' => false];
        }
    }

    /**
     * The volume behind the façade (never seen from the front, but casts the right shadow).
     *
     * @return list<Part>
     */
    private function body(): array
    {
        $innerDepth = $this->depth - self::WALL;

        return [$this->box(self::MATERIAL_RENDER, $this->width, $this->eavesHeight, $innerDepth, 0, $this->eavesHeight / 2, -self::WALL - $innerDepth / 2)];
    }

    /**
     * The front wall, split into solid pieces around the openings: vertical
     * bands between opening edges, each band solid where no opening is.
     *
     * @return list<Part>
     */
    private function facade(): array
    {
        $edges = [-$this->width / 2, $this->width / 2];
        foreach ($this->openings as $opening) {
            $edges[] = $opening['x'] - $opening['width'] / 2;
            $edges[] = $opening['x'] + $opening['width'] / 2;
        }
        $edges = array_values(array_unique($edges, SORT_REGULAR));
        sort($edges);

        $parts = [];
        for ($i = 0, $count = count($edges) - 1; $i < $count; ++$i) {
            [$left, $right] = [$edges[$i], $edges[$i + 1]];
            if ($right - $left < 1) {
                continue;
            }
            $centre = ($left + $right) / 2;
            $gaps = array_values(array_filter(
                $this->openings,
                static fn (array $o): bool => abs($o['x'] - $centre) < $o['width'] / 2,
            ));
            usort($gaps, static fn (array $a, array $b): int => $a['y'] <=> $b['y']);

            $bottom = 0.0;
            foreach ([...$gaps, ['y' => $this->eavesHeight, 'height' => 0]] as $gap) {
                if ($gap['y'] > $bottom) {
                    $parts = [...$parts, ...$this->wallPiece($left, $right, $bottom, $gap['y'])];
                }
                $bottom = $gap['y'] + $gap['height'];
            }
        }

        return $parts;
    }

    /**
     * @return list<Part> a piece of render, with the darker plinth where it reaches the ground
     */
    private function wallPiece(float $left, float $right, float $bottom, float $top): array
    {
        $width = $right - $left;
        $x = ($left + $right) / 2;
        $parts = [];
        if ($bottom < self::PLINTH) {
            $plinthTop = min($top, self::PLINTH);
            $parts[] = $this->box(self::MATERIAL_PLINTH, $width, $plinthTop - $bottom, self::WALL + 20, $x, ($bottom + $plinthTop) / 2, -self::WALL / 2 + 10);
            $bottom = $plinthTop;
        }
        if ($top > $bottom) {
            $parts[] = $this->box(self::MATERIAL_RENDER, $width, $top - $bottom, self::WALL, $x, ($bottom + $top) / 2, -self::WALL / 2);
        }

        return $parts;
    }

    /** @return list<Part> frames, glass, sills and the door's middle mullion */
    private function windows(): array
    {
        $parts = [];
        foreach ($this->openings as $o) {
            [$x, $y, $w, $h] = [$o['x'], $o['y'], $o['width'], $o['height']];
            $z = -self::GLASS_INSET + self::FRAME_DEPTH / 2;
            $parts[] = $this->box(self::MATERIAL_GLASS, $w - 2 * self::FRAME, $h - 2 * self::FRAME, 20, $x, $y + $h / 2, -self::GLASS_INSET);
            $parts[] = $this->box(self::MATERIAL_FRAME, $w, self::FRAME, self::FRAME_DEPTH, $x, $y + $h - self::FRAME / 2, $z);
            $parts[] = $this->box(self::MATERIAL_FRAME, $w, self::FRAME, self::FRAME_DEPTH, $x, $y + self::FRAME / 2, $z);
            $parts[] = $this->box(self::MATERIAL_FRAME, self::FRAME, $h, self::FRAME_DEPTH, $x - $w / 2 + self::FRAME / 2, $y + $h / 2, $z);
            $parts[] = $this->box(self::MATERIAL_FRAME, self::FRAME, $h, self::FRAME_DEPTH, $x + $w / 2 - self::FRAME / 2, $y + $h / 2, $z);
            // casements: a sliding door has one mullion, windows two sashes
            $parts[] = $this->box(self::MATERIAL_FRAME, self::FRAME, $h, self::FRAME_DEPTH, $x, $y + $h / 2, $z);
            // reveals: the wall's depth around the opening
            $parts[] = $this->box(self::MATERIAL_RENDER, $w, 10, self::GLASS_INSET, $x, $y + $h - 5, -self::GLASS_INSET / 2);
            $parts[] = $this->box(self::MATERIAL_RENDER, 10, $h, self::GLASS_INSET, $x - $w / 2 + 5, $y + $h / 2, -self::GLASS_INSET / 2);
            $parts[] = $this->box(self::MATERIAL_RENDER, 10, $h, self::GLASS_INSET, $x + $w / 2 - 5, $y + $h / 2, -self::GLASS_INSET / 2);
            if (!$o['door']) {
                $parts[] = $this->box(self::MATERIAL_SILL, $w + 80, 40, self::GLASS_INSET + self::SILL_DEPTH, $x, $y - 20, -(self::GLASS_INSET - self::SILL_DEPTH) / 2);
            }
        }

        return $parts;
    }

    /** @return list<Part> two roof planes, gable walls, fascias and a chimney */
    private function roof(): array
    {
        $pitch = deg2rad(self::PITCH_DEGREES);
        $halfDepth = $this->depth / 2;
        $ridgeZ = -$halfDepth;
        $ridgeY = $this->eavesHeight + $halfDepth * tan($pitch);
        $run = $halfDepth + self::EAVES_OVERHANG;
        $slope = $run / cos($pitch);
        $roofWidth = $this->width + 2 * self::GABLE_OVERHANG;
        $parts = [];

        foreach ([1, -1] as $side) {
            // the roof plane's underside runs from the ridge down past the wall by the overhang
            $midZ = $ridgeZ + $side * $run / 2;
            $midY = $ridgeY - $run / 2 * tan($pitch);
            $parts[] = $this->box(
                self::MATERIAL_ROOF,
                $roofWidth,
                self::ROOF_THICKNESS,
                $slope,
                0,
                $midY + self::ROOF_THICKNESS / 2 / cos($pitch),
                $midZ,
                $side * $pitch,
            );
            $eavesZ = $ridgeZ + $side * $run;
            $parts[] = $this->box(self::MATERIAL_FASCIA, $roofWidth, self::FASCIA, 40, 0, $ridgeY - $run * tan($pitch) + 20, $eavesZ + $side * 20);
        }

        foreach ([-1, 1] as $side) {
            // gable triangle above the eaves, profile in (−z, y) so it can turn onto the side wall
            $parts[] = new Part(
                null,
                self::LABEL,
                new Extrusion([[0, $this->eavesHeight], [$this->depth, $this->eavesHeight], [$halfDepth, $ridgeY]], self::WALL),
                self::MATERIAL_RENDER,
                new Vector3($side * ($this->width / 2 - self::WALL / 2), 0, $this->offsetZ),
                new Vector3(0, M_PI / 2, 0),
            );
        }

        $chimneyHeight = 1400;
        $chimneyX = $this->width * 0.28;
        $chimneyZ = $ridgeZ - 900;
        $parts[] = $this->box(self::MATERIAL_PLINTH, 600, $chimneyHeight + 900, 600, $chimneyX, $ridgeY - 900 * tan($pitch) + ($chimneyHeight - 900) / 2 + 200, $chimneyZ);

        return $parts;
    }

    private function box(string $material, float $w, float $h, float $d, float $x, float $y, float $z, float $tilt = 0): Part
    {
        return new Part(null, self::LABEL, new Box($w, $h, $d), $material, new Vector3($x, $y, $z + $this->offsetZ), new Vector3($tilt, 0, 0));
    }
}
