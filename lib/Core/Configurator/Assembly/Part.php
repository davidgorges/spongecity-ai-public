<?php

declare(strict_types=1);

namespace Core\Configurator\Assembly;

/**
 * One physical part of the construction, or a piece of scenery.
 *
 * - with a SKU it goes on the bill of materials and is priced;
 * - with a shape it is drawn (accessories like screws or drivers have none);
 * - scenery (the house wall, the ground) has a shape but no SKU;
 * - decoration (furniture, a fire pit) is scenery the viewer can hide, so
 *   the customer can look at the bare product;
 * - a draped part lies on the ground (a plot, a road, a surface): on a site with
 *   terrain the view lays it over the ground instead of raising it as a whole;
 * - an article drawn from several pieces (a gate set: posts, frame, infill)
 *   gives every piece the article's SKU, so all of them are product and show
 *   the article on hover, but only one piece counts: the others pass
 *   `counted: false` and stay off the bill of materials.
 */
final readonly class Part
{
    public Measure $measure;

    /**
     * @param Measure|null $measure how the part counts on the BOM; a piece if omitted
     * @param bool         $decor   staging the customer can switch off (never on the BOM)
     * @param bool         $counted false for a further piece of an article that another part already counts
     * @param bool         $draped  lies on the ground: the view follows the terrain with it
     */
    public function __construct(
        public ?string $sku,
        public string $label,
        public ?Shape $shape = null,
        public ?string $material = null,
        public Vector3 $position = new Vector3(),
        public Vector3 $rotation = new Vector3(),
        ?Measure $measure = null,
        public bool $decor = false,
        public bool $counted = true,
        public bool $draped = false,
    ) {
        $this->measure = $measure ?? Measure::piece();
    }

    /**
     * The part as a piece of a group whose origin stands at x/z in plan,
     * turned about the vertical by $angle (radians, as three.js turns about
     * Y): its position is read in the group's frame. A tilted part (a roof
     * plane) keeps its tilt.
     */
    public function placedAt(float $x, float $z, float $angle = 0.0): self
    {
        $cos = cos($angle);
        $sin = sin($angle);
        $local = $this->position;

        return new self(
            $this->sku,
            $this->label,
            $this->shape,
            $this->material,
            new Vector3($x + $local->x * $cos + $local->z * $sin, $local->y, $z - $local->x * $sin + $local->z * $cos),
            Turn::aboutY($this->rotation, $angle),
            $this->measure,
            $this->decor,
            $this->counted,
            $this->draped,
        );
    }

    /** The part moved up (or down) by $dy, e.g. onto the ground it stands on. */
    public function raisedBy(float $dy): self
    {
        if (0.0 === $dy) {
            return $this;
        }

        return new self(
            $this->sku,
            $this->label,
            $this->shape,
            $this->material,
            new Vector3($this->position->x, $this->position->y + $dy, $this->position->z),
            $this->rotation,
            $this->measure,
            $this->decor,
            $this->counted,
            $this->draped,
        );
    }

    /** The part as one that lies on the ground (see the class comment). */
    public function drapedOverTerrain(): self
    {
        return new self($this->sku, $this->label, $this->shape, $this->material, $this->position, $this->rotation, $this->measure, $this->decor, $this->counted, true);
    }

    public function isOnBom(): bool
    {
        return null !== $this->sku && $this->counted;
    }
}
