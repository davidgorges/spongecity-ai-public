<?php

declare(strict_types=1);

namespace Core\Configurator\Bom;

use Core\Configurator\Assembly\Assembly;

/**
 * The parts of an assembly grouped by SKU and measure: four posts of 2500 mm
 * are one line, a post of 2700 mm another (it is cut differently).
 *
 * @implements \IteratorAggregate<int, BomLine>
 */
final readonly class BillOfMaterials implements \IteratorAggregate, \Countable
{
    /**
     * @param list<BomLine> $lines
     */
    public function __construct(public array $lines)
    {
    }

    public static function fromAssembly(Assembly $assembly): self
    {
        /** @var array<string, BomLine> $lines */
        $lines = [];
        foreach ($assembly as $part) {
            $sku = $part->sku;
            if (null === $sku || !$part->counted) {
                continue;
            }
            $key = $sku.'|'.$part->measure->key();
            $quantity = isset($lines[$key]) ? $lines[$key]->quantity + 1 : 1;
            $lines[$key] = new BomLine($sku, $part->label, $part->measure, $quantity);
        }

        // one block per SKU, in the order the SKUs first appear: the 2.30 m front
        // posts and the 2.93 m rear posts are listed together
        $order = array_flip(array_unique(array_map(static fn (BomLine $line): string => $line->sku, array_values($lines))));
        $sorted = array_values($lines);
        usort($sorted, static fn (BomLine $a, BomLine $b): int => $order[$a->sku] <=> $order[$b->sku]);

        return new self($sorted);
    }

    /**
     * @return \ArrayIterator<int, BomLine>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->lines);
    }

    public function count(): int
    {
        return count($this->lines);
    }
}
