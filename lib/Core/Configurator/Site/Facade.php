<?php

declare(strict_types=1);

namespace Core\Configurator\Site;

use Core\Configurator\Scene\ProceduralTexture;

/**
 * What the walls of a building footprint look like: a façade pattern with windows
 * per storey (procedural_textures.js) and the colours it typically comes in. The
 * texture is near-white and tinted by the building's colour.
 */
enum Facade: string
{
    /** rendered masonry with punched windows: most housing */
    case Plaster = 'plaster';
    /** facing brick with punched windows: northern German housing, schools, churches */
    case Brick = 'brick';
    /** continuous window bands: offices, post-war schools, high-rises */
    case Ribbon = 'ribbon';
    /** profiled metal sheets with a strip of high windows: halls, warehouses */
    case Cladding = 'cladding';
    /** no windows: garages, sheds */
    case Plain = 'plain';

    /** a window bay: one storey high, one window wide (metres) */
    public const float BAY = 3.0;
    /** one repeat of a window pattern holds this many bays across and storeys up, each window a little different */
    public const int BAYS_PER_REPEAT = 4;
    /** the colour of lit rooms in the evening (only the windows the pattern lights glow) */
    public const string NIGHT_GLOW = '#ffd29a';
    public const float NIGHT_GLOW_INTENSITY = 1.4;
    private const float PLAIN_TEXTURE_SIZE = 1.6;

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function texture(): ProceduralTexture
    {
        return match ($this) {
            self::Plaster => ProceduralTexture::FacadePlaster,
            self::Brick => ProceduralTexture::FacadeBrick,
            self::Ribbon => ProceduralTexture::FacadeRibbon,
            self::Cladding => ProceduralTexture::FacadeCladding,
            self::Plain => ProceduralTexture::Plaster,
        };
    }

    /** metres per texture repeat */
    public function textureSize(): float
    {
        return self::Plain === $this ? self::PLAIN_TEXTURE_SIZE : self::BAY * self::BAYS_PER_REPEAT;
    }

    public function hasWindows(): bool
    {
        return self::Plain !== $this;
    }

    /**
     * Colours this kind of façade typically has, to pick from when the map has none.
     *
     * @return non-empty-list<string>
     */
    public function colours(): array
    {
        return match ($this) {
            // cream, off-white, light ochre, warm grey, sand, pale yellow
            self::Plaster => ['#efe6d6', '#f2efe8', '#e6d3b3', '#d9d4cc', '#e9d9c4', '#eee2b8'],
            // red, dark red clinker, light red, brown clinker, yellow brick
            self::Brick => ['#a4553c', '#8e4632', '#b46a4c', '#7b4334', '#c9a27a'],
            // light concrete, cool grey, warm white, blue-grey
            self::Ribbon => ['#d8d8d4', '#c9ccce', '#e6e3dc', '#b9c2c8'],
            // light grey, blue-grey, beige, steel blue
            self::Cladding => ['#c3c7ca', '#9aa5ab', '#cbc5b6', '#7e909b'],
            self::Plain => ['#cfcac2', '#bdb8b0'],
        };
    }
}
