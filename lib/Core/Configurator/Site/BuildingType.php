<?php

declare(strict_types=1);

namespace Core\Configurator\Site;

use Core\Configurator\Scenery\Building;

/**
 * What a building footprint is used for, in classes like those of urban energy
 * simulation (SimStadt: building function + IWU/TABULA archetypes such as detached
 * house, terraced house, apartment block, high-rise). Gives an untagged building a
 * plausible façade and roof.
 */
enum BuildingType: string
{
    case House = 'house';
    case Terrace = 'terrace';
    case Apartments = 'apartments';
    case HighRise = 'highrise';
    case School = 'school';
    case Office = 'office';
    case Retail = 'retail';
    case Industrial = 'industrial';
    case Public = 'public';
    case Church = 'church';
    case Garage = 'garage';
    case Other = 'other';

    /** from this many storeys, housing counts as a high-rise (Hochhaus, > 22 m) */
    public const int HIGH_RISE_STOREYS = 8;

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The façades this kind of building usually has, the most common first.
     *
     * @return non-empty-list<Facade>
     */
    public function facades(): array
    {
        return match ($this) {
            self::House, self::Terrace => [Facade::Plaster, Facade::Brick],
            self::Apartments => [Facade::Plaster, Facade::Brick, Facade::Plaster, Facade::Render],
            // rendered walls with single windows (old and new), red brick (pre-war), window bands (1960s/70s)
            self::School => [Facade::Render, Facade::Brick, Facade::Plaster, Facade::Ribbon],
            self::HighRise, self::Office, self::Retail => [Facade::Ribbon],
            self::Industrial => [Facade::Cladding],
            self::Public => [Facade::Plaster, Facade::Brick],
            self::Church => [Facade::Brick],
            self::Garage => [Facade::Plain],
            self::Other => [Facade::Plaster],
        };
    }

    /**
     * Colours a roof of this shape typically has: gravel or bitumen on flat roofs,
     * red, brown or anthracite tiles on pitched ones.
     *
     * @return non-empty-list<string>
     */
    public static function roofColours(string $roof): array
    {
        return Building::ROOF_FLAT === $roof
            ? ['#8d8a84', '#77756f', '#9a968d', '#6c6b68']
            : ['#a4533b', '#8a4636', '#4c4b4d', '#6b4a3c'];
    }

    /** the roof it usually has when the map does not say */
    public function roof(): string
    {
        return match ($this) {
            self::House, self::Terrace, self::Church => Building::ROOF_GABLE,
            default => Building::ROOF_FLAT,
        };
    }
}
