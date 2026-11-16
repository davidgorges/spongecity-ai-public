<?php

declare(strict_types=1);

namespace Core\Configurator\Scene;

/**
 * Physically based surface, mapped onto three.js MeshPhysicalMaterial.
 */
final readonly class Material
{
    /**
     * @param string               $color        hex colour, e.g. "#383e42"
     * @param float                $transmission 0 = opaque, 1 = clear glass (light passes through, refracted by $ior)
     * @param float                $clearcoat    0..1, a thin lacquer layer on top (powder coating, varnish)
     * @param ProceduralTexture|null $texture    surface pattern generated in the browser, no image files
     * @param float                $textureSize  edge length in metres that one repeat of the texture covers
     * @param float                $emissive     0 = lit by the scene only, >0 = glows in its own colour (lights, LEDs)
     * @param string|null          $nightGlow    colour the surface glows in the evening mood (lamps, lit windows)
     * @param float                $nightGlowIntensity strength of that glow
     * @param bool                 $lightPool    in the evening, parts of this material throw a pool of light on the floor below
     */
    public function __construct(
        public string $color,
        public float $roughness = 0.5,
        public float $metalness = 0.0,
        public float $opacity = 1.0,
        public float $transmission = 0.0,
        public float $ior = 1.5,
        public float $clearcoat = 0.0,
        public ?ProceduralTexture $texture = null,
        public float $textureSize = 1.0,
        public float $emissive = 0.0,
        public ?string $nightGlow = null,
        public float $nightGlowIntensity = 0.0,
        public bool $lightPool = false,
    ) {
    }

    /**
     * The material's entry in the scene JSON (see docs/configurator/scene-format.md).
     *
     * @ignore-large-array-rule
     *
     * @return array<string, string|float|bool|null>
     */
    public function toArray(): array
    {
        return [
            'color' => $this->color,
            'roughness' => $this->roughness,
            'metalness' => $this->metalness,
            'opacity' => $this->opacity,
            'transmission' => $this->transmission,
            'ior' => $this->ior,
            'clearcoat' => $this->clearcoat,
            'texture' => $this->texture?->value,
            'textureSize' => $this->textureSize,
            'emissive' => $this->emissive,
            'nightGlow' => $this->nightGlow,
            'nightGlowIntensity' => $this->nightGlowIntensity,
            'lightPool' => $this->lightPool,
        ];
    }
}
