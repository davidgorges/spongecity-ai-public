<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo;

use Core\Configurator\Scenery\Building;
use Core\Configurator\Site\BuildingType;
use Core\Configurator\Site\Facade;
use Core\Configurator\Site\SiteObject as O;

/**
 * What an OpenStreetMap building looks like, from its tags. Classified like in urban
 * energy simulation (SimStadt): its function (building=*) and storeys give a
 * BuildingType, which suggests a façade and roof; building:material, building:colour,
 * roof:shape and roof:colour from the map take precedence. Colours the map does not
 * give are picked from the façade's palette, the same for a building on every import.
 * Used for the site's buildings and for the real surroundings around it.
 */
final readonly class BuildingTags
{
    public const string TAG_BUILDING = 'building';
    public const string TAG_LEVELS = 'building:levels';
    public const string TAG_HEIGHT = 'height';
    public const string TAG_MATERIAL = 'building:material';
    public const string TAG_COLOUR = 'building:colour';
    public const string TAG_ROOF_SHAPE = 'roof:shape';
    public const string TAG_ROOF_COLOUR = 'roof:colour';

    private const float STOREY_METRES = 3.0;
    private const float DEFAULT_BUILDING_METRES = 9.0;
    private const float MILLIMETRES_PER_METRE = 1000.0;

    /** building=* → use; anything else (yes, …) is Other, or Apartments when it is tall */
    private const array BUILDING_TYPES = [
        'house' => BuildingType::House, 'detached' => BuildingType::House, 'semidetached_house' => BuildingType::House,
        'bungalow' => BuildingType::House, 'farm' => BuildingType::House, 'cabin' => BuildingType::House,
        'terrace' => BuildingType::Terrace,
        'apartments' => BuildingType::Apartments, 'residential' => BuildingType::Apartments, 'dormitory' => BuildingType::Apartments,
        'school' => BuildingType::School, 'kindergarten' => BuildingType::School, 'university' => BuildingType::School, 'college' => BuildingType::School,
        'office' => BuildingType::Office, 'commercial' => BuildingType::Office, 'hotel' => BuildingType::Office,
        'retail' => BuildingType::Retail, 'supermarket' => BuildingType::Retail, 'kiosk' => BuildingType::Retail,
        'industrial' => BuildingType::Industrial, 'warehouse' => BuildingType::Industrial, 'hangar' => BuildingType::Industrial,
        'sports_hall' => BuildingType::Industrial, 'manufacture' => BuildingType::Industrial, 'service' => BuildingType::Industrial,
        'public' => BuildingType::Public, 'civic' => BuildingType::Public, 'government' => BuildingType::Public,
        'hospital' => BuildingType::Public, 'train_station' => BuildingType::Public, 'fire_station' => BuildingType::Public,
        'church' => BuildingType::Church, 'chapel' => BuildingType::Church, 'cathedral' => BuildingType::Church, 'mosque' => BuildingType::Church,
        'garage' => BuildingType::Garage, 'garages' => BuildingType::Garage, 'carport' => BuildingType::Garage, 'shed' => BuildingType::Garage, 'hut' => BuildingType::Garage,
    ];
    /** without a use, a building this tall is taken for an apartment block (the usual case in a city) */
    private const int UNTYPED_APARTMENT_STOREYS = 3;
    private const array FACADE_MATERIALS = [
        'brick' => Facade::Brick, 'plaster' => Facade::Plaster, 'concrete' => Facade::Plaster, 'stone' => Facade::Plaster,
        'glass' => Facade::Ribbon, 'mirror' => Facade::Ribbon, 'metal' => Facade::Cladding, 'metal_plates' => Facade::Cladding,
        'wood' => Facade::Plaster, 'timber_framing' => Facade::Plaster,
    ];
    private const array ROOF_SHAPES = [
        'flat' => Building::ROOF_FLAT, 'gabled' => Building::ROOF_GABLE, 'hipped' => Building::ROOF_HIP, 'half-hipped' => Building::ROOF_HIP,
        'pyramidal' => Building::ROOF_HIP, 'skillion' => Building::ROOF_MONOPITCH, 'mansard' => Building::ROOF_GABLE,
        'gambrel' => Building::ROOF_GABLE, 'saltbox' => Building::ROOF_GABLE,
    ];
    /** colour names used in OpenStreetMap, as hex */
    private const array COLOUR_NAMES = [
        'white' => '#f2f0ea', 'black' => '#2e2e2e', 'grey' => '#9a9a96', 'gray' => '#9a9a96', 'darkgrey' => '#5e5e5c',
        'darkgray' => '#5e5e5c', 'lightgrey' => '#c8c8c4', 'lightgray' => '#c8c8c4', 'silver' => '#c0c0c0', 'red' => '#a4553c',
        'darkred' => '#7b3a2e', 'maroon' => '#7b3a2e', 'brown' => '#7b5a44', 'orange' => '#c98a52', 'yellow' => '#e8d68e',
        'beige' => '#e0d3b8', 'cream' => '#efe6d0', 'tan' => '#cdb38e', 'green' => '#6f8a5a', 'blue' => '#6f87a0',
    ];
    private const string HEX_COLOUR = '/^#[0-9a-f]{6}$/';

    /**
     * Use, façade and roof of a building from its tags, completed from its type.
     *
     * @param array<string, mixed>         $tags
     * @param list<array{0: int, 1: int}> $points
     *
     * @return array<string, string>
     */
    public function appearance(array $tags, array $points): array
    {
        $storeys = $this->height($tags) / self::MILLIMETRES_PER_METRE / self::STOREY_METRES;
        $type = self::BUILDING_TYPES[$this->tag($tags, self::TAG_BUILDING)] ?? null;
        $type ??= $storeys >= self::UNTYPED_APARTMENT_STOREYS ? BuildingType::Apartments : BuildingType::Other;
        if (BuildingType::Apartments === $type && $storeys >= BuildingType::HIGH_RISE_STOREYS) {
            $type = BuildingType::HighRise;
        }
        // the same pick for a building on every import: seeded by where it stands, salted per choice
        $position = json_encode($points[0]) ?: '';
        $pick = static fn (array $options, string $salt): mixed => $options[crc32($salt.$position) % count($options)];

        $facade = self::FACADE_MATERIALS[$this->tag($tags, self::TAG_MATERIAL)] ?? $pick($type->facades(), O::FACADE);
        $roof = self::ROOF_SHAPES[$this->tag($tags, self::TAG_ROOF_SHAPE)] ?? $type->roof();

        return [
            O::USE => $type->value,
            O::FACADE => $facade->value,
            O::COLOR => $this->colour($tags, self::TAG_COLOUR) ?? $pick($facade->colours(), O::COLOR),
            O::ROOF => $roof,
            O::ROOF_COLOR => $this->colour($tags, self::TAG_ROOF_COLOUR) ?? $pick(BuildingType::roofColours($roof), O::ROOF_COLOR),
        ];
    }

    /**
     * @param array<string, mixed> $tags
     */
    private function colour(array $tags, string $key): ?string
    {
        $value = str_replace([' ', '_'], '', $this->tag($tags, $key));
        if (1 === preg_match(self::HEX_COLOUR, $value)) {
            return $value;
        }

        return self::COLOUR_NAMES[$value] ?? null;
    }

    /**
     * @param array<string, mixed> $tags
     */
    private function tag(array $tags, string $key): string
    {
        return is_string($tags[$key] ?? null) ? strtolower(trim($tags[$key])) : '';
    }

    /**
     * Height in millimetres: height=*, else building:levels × a storey, else a default.
     *
     * @param array<string, mixed> $tags
     */
    public function height(array $tags): int
    {
        if (is_numeric($tags[self::TAG_HEIGHT] ?? null)) {
            return (int) round((float) $tags[self::TAG_HEIGHT] * self::MILLIMETRES_PER_METRE);
        }
        $levels = is_numeric($tags[self::TAG_LEVELS] ?? null) ? (float) $tags[self::TAG_LEVELS] : null;

        return (int) round(($levels ? $levels * self::STOREY_METRES : self::DEFAULT_BUILDING_METRES) * self::MILLIMETRES_PER_METRE);
    }
}
