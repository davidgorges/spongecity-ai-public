<?php

declare(strict_types=1);

namespace App\SpongeCity\Terrain;

/**
 * A square grid of ground heights in plan metres (x east, z south): rows from north
 * (smallest z) to south, columns west to east, heights in centimetres relative to the
 * plan's origin. Read between the nodes bilinearly, the same way the 3D view does.
 */
final readonly class TerrainGrid
{
    public const string ORIGIN_X = 'originX';
    public const string ORIGIN_Z = 'originZ';
    public const string SPACING = 'spacing';
    public const string COLUMNS = 'columns';
    public const string ROWS = 'rows';
    public const string HEIGHTS = 'heights';

    private const float CENTIMETRES_PER_METRE = 100.0;

    /**
     * @param list<int> $heights centimetres, row by row
     */
    public function __construct(
        public float $originX,
        public float $originZ,
        public float $spacing,
        public int $columns,
        public int $rows,
        public array $heights,
    ) {
    }

    public function covers(float $x, float $z): bool
    {
        return $x >= $this->originX && $z >= $this->originZ
            && $x <= $this->originX + ($this->columns - 1) * $this->spacing
            && $z <= $this->originZ + ($this->rows - 1) * $this->spacing;
    }

    /**
     * Metres relative to the origin; at the edge the outermost nodes. Linear within the two
     * triangles of each cell (split from its north-west to its south-east node), exactly as
     * the view builds the ground, so what stands on it here stands on it there.
     */
    public function heightAt(float $x, float $z): float
    {
        $u = max(0.0, min($this->columns - 1.0, ($x - $this->originX) / $this->spacing));
        $v = max(0.0, min($this->rows - 1.0, ($z - $this->originZ) / $this->spacing));
        [$c, $r] = [min((int) floor($u), $this->columns - 2), min((int) floor($v), $this->rows - 2)];
        [$fu, $fv] = [$u - $c, $v - $r];
        $at = fn (int $col, int $row): float => $this->heights[$row * $this->columns + $col] / self::CENTIMETRES_PER_METRE;
        $h00 = $at($c, $r);
        $h11 = $at($c + 1, $r + 1);

        return $fu >= $fv
            ? $h00 + $fu * ($at($c + 1, $r) - $h00) + $fv * ($h11 - $at($c + 1, $r))
            : $h00 + $fv * ($at($c, $r + 1) - $h00) + $fu * ($h11 - $at($c, $r + 1));
    }

    /** metres at a node */
    public function node(int $column, int $row): float
    {
        return $this->heights[$row * $this->columns + $column] / self::CENTIMETRES_PER_METRE;
    }

    /**
     * @return array{originX: float, originZ: float, spacing: float, columns: int, rows: int, heights: list<int>}
     */
    public function toArray(): array
    {
        return [
            self::ORIGIN_X => $this->originX,
            self::ORIGIN_Z => $this->originZ,
            self::SPACING => $this->spacing,
            self::COLUMNS => $this->columns,
            self::ROWS => $this->rows,
            self::HEIGHTS => $this->heights,
        ];
    }
}
