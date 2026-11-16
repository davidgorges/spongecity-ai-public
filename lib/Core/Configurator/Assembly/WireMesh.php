<?php

declare(strict_types=1);

namespace Core\Configurator\Assembly;

use Webmozart\Assert\Assert;

/**
 * A welded wire mat (double-bar mesh, gate infill, gabion walls) in the part's
 * local X/Y plane, centred on the part's position: vertical wires every
 * `pitch`, crossed by horizontal wires every `rowSpacing` from the bottom.
 * With `doubleBars` each row is a pair of wires, one in front of and one
 * behind the verticals (Doppelstabmatte). The verticals stick out `overhang`
 * above the top row. The browser builds the wires itself, so one mat is one
 * part however many wires it has. Millimetres.
 */
final readonly class WireMesh implements Shape
{
    public const string TYPE = 'wire_mesh';

    public function __construct(
        public float $width,
        public float $height,
        public float $pitch = 50,
        public float $rowSpacing = 200,
        public float $verticalWire = 5,
        public float $horizontalWire = 6,
        public bool $doubleBars = true,
        public float $overhang = 30,
    ) {
        Assert::greaterThan($width, 0);
        Assert::greaterThan($height, $overhang);
        Assert::greaterThan($pitch, 0);
        Assert::greaterThan($rowSpacing, 0);
    }

    /** Front to back: the verticals plus a horizontal wire on each side (or one behind them). */
    public function thickness(): float
    {
        return $this->doubleBars ? $this->verticalWire + 2 * $this->horizontalWire : $this->verticalWire + $this->horizontalWire;
    }

    public function type(): string
    {
        return self::TYPE;
    }

    /**
     * @ignore-large-array-rule mirrors the scene JSON contract (docs/configurator/scene-format.md)
     */
    public function toArray(): array
    {
        return [
            'size' => [$this->width, $this->height, $this->thickness()],
            'pitch' => $this->pitch,
            'rowSpacing' => $this->rowSpacing,
            'wires' => [$this->verticalWire, $this->horizontalWire],
            'doubleBars' => $this->doubleBars,
            'overhang' => $this->overhang,
        ];
    }
}
