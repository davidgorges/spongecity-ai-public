<?php

declare(strict_types=1);

namespace App\Controller;

/**
 * Hamburg schools the home page offers to try in one click. Hamburg, because its official
 * geodata (LOD2 buildings, terrain, soil infiltration) and SimStadt only cover Hamburg. Each
 * name geocodes (Nominatim) to the school grounds themselves, not just a street.
 */
enum ExampleSchool: string
{
    case BrechtSchule = 'Brecht-Schule';
    case Johanneum = 'Gelehrtenschule des Johanneums';
    case AlbertSchweitzer = 'Albert-Schweitzer-Gymnasium';

    /** what the home form posts: the name plus the city, so the geocoder picks the Hamburg school */
    public function place(): string
    {
        return $this->value.', Hamburg';
    }

    /** the 3D view of the school, under assets/ */
    public function image(): string
    {
        return match ($this) {
            self::BrechtSchule => 'images/schools/brecht-schule.jpg',
            self::Johanneum => 'images/schools/johanneum.jpg',
            self::AlbertSchweitzer => 'images/schools/albert-schweitzer.jpg',
        };
    }

    public function district(): string
    {
        return match ($this) {
            self::BrechtSchule => 'St. Georg',
            self::Johanneum => 'Winterhude',
            self::AlbertSchweitzer => 'Ohlsdorf',
        };
    }
}
