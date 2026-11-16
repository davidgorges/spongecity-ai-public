<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo;

/**
 * Plan metres (x east, z south, origin the plan's centre) to UTM 32 and back. Over a
 * few hundred metres the mapping is affine (a rotation by the grid convergence, about
 * 0.8° in Hamburg, and the scale): measured once at the origin and two steps away.
 */
final readonly class UtmPlanTransform
{
    private const float STEP_METRES = 100.0;

    /** UTM of the origin, and the UTM change per plan metre east (x) and south (z) */
    private float $e0;
    private float $n0;
    private float $exPerX;
    private float $nxPerX;
    private float $ezPerZ;
    private float $nzPerZ;

    public function __construct(GeoPoint $centre)
    {
        $projection = new LocalProjection($centre);
        [$this->e0, $this->n0] = Utm::fromGeo($centre->latitude, $centre->longitude);
        [$ex, $nx] = Utm::fromGeo(...$projection->toGeo(self::STEP_METRES, 0));
        [$ez, $nz] = Utm::fromGeo(...$projection->toGeo(0, self::STEP_METRES));
        $this->exPerX = ($ex - $this->e0) / self::STEP_METRES;
        $this->nxPerX = ($nx - $this->n0) / self::STEP_METRES;
        $this->ezPerZ = ($ez - $this->e0) / self::STEP_METRES;
        $this->nzPerZ = ($nz - $this->n0) / self::STEP_METRES;
    }

    /**
     * @return array{0: float, 1: float} [easting, northing]
     */
    public function toUtm(float $x, float $z): array
    {
        return [$this->e0 + $this->exPerX * $x + $this->ezPerZ * $z, $this->n0 + $this->nxPerX * $x + $this->nzPerZ * $z];
    }

    /**
     * @return array{0: float, 1: float} plan metres [x, z]
     */
    public function toPlan(float $easting, float $northing): array
    {
        [$de, $dn] = [$easting - $this->e0, $northing - $this->n0];
        $determinant = $this->exPerX * $this->nzPerZ - $this->ezPerZ * $this->nxPerX;

        return [($de * $this->nzPerZ - $dn * $this->ezPerZ) / $determinant, ($dn * $this->exPerX - $de * $this->nxPerX) / $determinant];
    }
}
