<?php

declare(strict_types=1);

namespace Core\Configurator\Site;

use Core\Configurator\Scenery\Building;

/**
 * The kinds of things a site holds and their fields: one specification the
 * normaliser (SiteType), the JSON Schema for agents and the defaults are all
 * read from. Millimetres and degrees; x to the right seen from the street,
 * z towards the street, the same plan coordinates as the product.
 */
final class SiteObject
{
    public const string ID = 'id';
    public const string TYPE = 'type';
    public const string LABEL = 'label';
    public const string X = 'x';
    public const string Z = 'z';
    public const string Y = 'y';
    public const string TURN = 'turn';
    public const string WIDTH = 'width';
    public const string DEPTH = 'depth';
    public const string HEIGHT = 'height';
    public const string DIAMETER = 'diameter';
    public const string THICKNESS = 'thickness';
    public const string STOREYS = 'storeys';
    public const string ROOF = 'roof';
    public const string PITCH = 'pitch';
    public const string RIDGE = 'ridge';
    public const string WALL_COLOR = 'wallColor';
    public const string ROOF_COLOR = 'roofColor';
    public const string COLOR = 'color';
    public const string DOOR = 'door';
    public const string DOOR_FACADE = 'doorFacade';
    public const string DOOR_OFFSET = 'doorOffset';
    public const string POINTS = 'points';
    public const string SURFACE = 'surface';
    public const string KIND = 'kind';
    public const string STORAGE = 'storage';
    /** a building's use (BuildingType) and the look of its walls (Facade) */
    public const string USE = 'use';
    public const string FACADE = 'facade';
    /**
     * where an object stands: its ground's height relative to the plan's origin, in millimetres
     * (the lowest ground under it). Set from the terrain whenever the site is saved, never by hand;
     * upright objects are raised by it, ground layers follow the terrain in the view.
     */
    public const string GROUND = 'ground';
    /**
     * a building's roof levels from the 3D building model: blocks of {points, height} (a low wing,
     * the main roof, a recessed top storey). When given, they are drawn instead of the outline at one height.
     */
    public const string PARTS = 'parts';
    /** where a building's look (facade, color) comes from: guessed from the data, or set by a person who knows it */
    public const string LOOK = 'look';
    public const string LOOK_DATA = 'data';
    public const string LOOK_USER = 'user';
    public const int MAX_PARTS = 40;
    /**
     * a pitched roof the parametric roof cannot draw (an L, a half-hip, towers, a mix of roofs), as the
     * 3D building model has it: {roofs, walls}, each a list of planar polygons of [x, y, z] in millimetres,
     * x/z in the plan, y above the building's ground; the walls only above the eaves (the building's
     * height), where the outline's façade ends. Set by the import, never by hand (not in the schema).
     */
    public const string MODEL = 'model';
    public const string MODEL_ROOFS = 'roofs';
    public const string MODEL_WALLS = 'walls';
    /** per kind: LoD3's Johanneum has 464 roof surfaces (a dome, dormers, a lantern) */
    public const int MAX_MODEL_SURFACES = 1000;
    public const int MAX_MODEL_CORNERS = 64;
    public const int MAX_GROUND = 50000;

    public const string HOUSE = 'house';
    public const string OUTBUILDING = 'outbuilding';
    public const string HEDGE = 'hedge';
    public const string WALL = 'wall';
    public const string TREE = 'tree';
    public const string PAVING = 'paving';
    public const string POOL = 'pool';
    public const string BOX = 'box';
    public const string CYLINDER = 'cylinder';
    public const string PRISM = 'prism';
    /** the analysed area: its ground surface is what the interventions replace */
    public const string PLOT = 'plot';
    /** a building footprint (e.g. from OpenStreetMap) with its height */
    public const string BUILDING = 'building';
    /** a street or path along its centre line (e.g. from OpenStreetMap) */
    public const string ROAD = 'road';

    /** what an AI agent may plan: measures that change the environmental balance (built by InterventionBuilder, SKU parts) */
    public const string NEWTREE = 'newtree';
    public const string RAINGARDEN = 'raingarden';
    public const string PERMEABLE = 'permeable';
    public const string PLANTING = 'planting';
    public const string SHADESAIL = 'shadesail';

    public const array INTERVENTIONS = [self::NEWTREE, self::RAINGARDEN, self::PERMEABLE, self::PLANTING, self::SHADESAIL];
    /** what stands on the ground and is raised to it as a whole; everything else lies on the ground and is draped over the terrain */
    public const array UPRIGHT = [
        self::HOUSE, self::OUTBUILDING, self::HEDGE, self::WALL, self::TREE, self::POOL, self::BOX, self::CYLINDER, self::PRISM,
        self::BUILDING, self::NEWTREE, self::SHADESAIL,
    ];
    public const array TYPES = [
        self::HOUSE, self::OUTBUILDING, self::HEDGE, self::WALL, self::TREE, self::PAVING, self::POOL, self::BOX, self::CYLINDER, self::PRISM,
        self::PLOT, self::BUILDING, self::ROAD, ...self::INTERVENTIONS,
    ];

    public const string SURFACE_ASPHALT = 'asphalt';
    public const string SURFACE_CONCRETE = 'concrete';
    public const string SURFACE_GRAVEL = 'gravel';
    public const string SURFACE_LAWN = 'lawn';
    public const string SURFACE_SOIL = 'soil';
    public const array SURFACES = [self::SURFACE_ASPHALT, self::SURFACE_CONCRETE, self::SURFACE_GRAVEL, self::SURFACE_LAWN, self::SURFACE_SOIL];

    public const string KIND_PAVERS = 'pavers';
    public const string KIND_GRAVEL = 'gravel';
    public const string KIND_GRASS_PAVERS = 'grass_pavers';
    public const array PERMEABLE_KINDS = [self::KIND_PAVERS, self::KIND_GRAVEL, self::KIND_GRASS_PAVERS];

    /** roads: asphalt for traffic, paving stones for people */
    public const string ROAD_CARRIAGEWAY = 'carriageway';
    public const string ROAD_FOOTWAY = 'footway';
    public const array ROAD_KINDS = [self::ROAD_CARRIAGEWAY, self::ROAD_FOOTWAY];

    public const string RIDGE_ALONG = 'along';
    public const string RIDGE_ACROSS = 'across';

    /** kinds of field */
    public const string INT = 'int';
    public const string ENUM = 'enum';
    public const string HEX = 'color';
    public const string OUTLINE = 'points';
    public const string BLOCKS = 'blocks';

    /** plan coordinates stay within this distance of the product (an 80 m import radius, with what reaches beyond it) */
    public const int REACH = 100000;
    public const int MAX_POINTS = 40;
    public const int MAX_LABEL = 40;

    /** what each type means, for the agent */
    public const array DESCRIPTIONS = [
        self::HOUSE => 'A house. x/z is the middle of its front façade, the house extends depth behind it; turn 0 faces the street.',
        self::OUTBUILDING => 'A garage, carport-like shed or garden house; x/z the middle of its front, like a house.',
        self::HEDGE => 'A hedge along points (a line, not closed).',
        self::WALL => 'A wall along points (a line, not closed), e.g. a boundary wall or a neighbour\'s fence.',
        self::TREE => 'A tree standing at x/z.',
        self::PAVING => 'A paved area (terrace, driveway, path) with the outline points (closed, any shape).',
        self::POOL => 'A swimming pool, x/z its centre.',
        self::BOX => 'Fallback: a box, x/z the centre of its base, y how high its base stands.',
        self::CYLINDER => 'Fallback: an upright cylinder, x/z the centre of its base.',
        self::PRISM => 'Fallback: an upright body over the outline points (closed).',
        self::PLOT => 'The analysed area (closed outline). surface is what the ground is made of today. Exactly one per site, created by load_site.',
        self::BUILDING => 'An existing building footprint (closed outline) with its height in millimetres, its use, façade and roof. Context from OpenStreetMap, not changeable by the design.',
        self::ROAD => 'An existing street or footpath along its centre line (points, not closed) with its width in millimetres. Context from OpenStreetMap, not changeable by the design.',
        self::NEWTREE => 'INTERVENTION: a newly planted shade tree at x/z (height is the expected height when grown). Gives shade and evaporative cooling.',
        self::RAINGARDEN => 'INTERVENTION: a sunken planted rain garden (closed outline) that takes runoff from surrounding sealed areas; storage is its ponding depth in millimetres.',
        self::PERMEABLE => 'INTERVENTION: a permeable surface (closed outline) replacing sealed ground: pavers with joints, gravel, or grass pavers.',
        self::PLANTING => 'INTERVENTION: unsealed planting or lawn (closed outline) replacing sealed ground.',
        self::SHADESAIL => 'INTERVENTION: a shade sail or pergola roof (closed outline) y millimetres above ground. Shade only; rain falls through to the ground below.',
    ];

    private const array POSITION = [
        self::X => [self::INT, -self::REACH, self::REACH, 0],
        self::Z => [self::INT, -self::REACH, self::REACH, 0],
    ];
    private const array TURN_FIELD = [self::TURN => [self::INT, -180, 180, 0]];

    private function __construct()
    {
    }

    /**
     * The fields of a type: name → [kind, min, max, default] for numbers,
     * [ENUM, values, default], [HEX, default], [OUTLINE, min points].
     *
     * @return array<string, array<int, mixed>>
     */
    public static function fields(string $type): array
    {
        $roof = [
            self::ROOF => [self::ENUM, Building::ROOFS, Building::ROOF_GABLE],
            self::PITCH => [self::INT, 0, 60, 35],
            self::RIDGE => [self::ENUM, [self::RIDGE_ALONG, self::RIDGE_ACROSS], self::RIDGE_ALONG],
        ];
        $color = static fn (string $default): array => [self::COLOR => [self::HEX, $default]];

        return match ($type) {
            self::HOUSE => [...self::POSITION, ...self::TURN_FIELD,
                self::WIDTH => [self::INT, 4000, 30000, 10000],
                self::DEPTH => [self::INT, 4000, 30000, 9000],
                self::STOREYS => [self::INT, 1, 4, 2],
                ...$roof,
                self::WALL_COLOR => [self::HEX, '#efe9df'],
                self::ROOF_COLOR => [self::HEX, '#4a4644'],
                self::DOOR_FACADE => [self::ENUM, [...Building::FACADES, Building::DOOR_NONE], Building::FACADE_FRONT],
                self::DOOR_OFFSET => [self::INT, -15000, 15000, 0],
            ],
            self::OUTBUILDING => [...self::POSITION, ...self::TURN_FIELD,
                self::WIDTH => [self::INT, 1500, 15000, 3000],
                self::DEPTH => [self::INT, 1500, 15000, 6000],
                self::HEIGHT => [self::INT, 2000, 6000, 2600],
                self::ROOF => [self::ENUM, Building::ROOFS, Building::ROOF_FLAT],
                self::PITCH => [self::INT, 0, 60, 15],
                self::RIDGE => $roof[self::RIDGE],
                self::WALL_COLOR => [self::HEX, '#d9d4cb'],
                self::ROOF_COLOR => [self::HEX, '#5b5f61'],
                self::DOOR => [self::ENUM, [Building::DOOR_GARAGE, Building::DOOR_ENTRANCE, Building::DOOR_NONE], Building::DOOR_GARAGE],
                self::DOOR_FACADE => [self::ENUM, Building::FACADES, Building::FACADE_FRONT],
            ],
            self::HEDGE => [
                self::POINTS => [self::OUTLINE, 2],
                self::HEIGHT => [self::INT, 400, 4000, 1800],
                self::THICKNESS => [self::INT, 300, 2000, 800],
            ],
            self::WALL => [
                self::POINTS => [self::OUTLINE, 2],
                self::HEIGHT => [self::INT, 200, 3000, 1200],
                self::THICKNESS => [self::INT, 50, 600, 240],
                ...$color('#bdb8ae'),
            ],
            // diameter: the recorded crown diameter (e.g. a tree register); 0 when unknown, then estimated from the height
            // existing trees as measured: Hamburg's street trees reach 57 m (laser scan); a new tree is planned up to 25 m
            self::TREE => [...self::POSITION, self::HEIGHT => [self::INT, 2000, 60000, 8000], self::DIAMETER => [self::INT, 0, 30000, 0]],
            self::PAVING => [self::POINTS => [self::OUTLINE, 3], ...$color('#b9b4aa')],
            self::POOL => [...self::POSITION, ...self::TURN_FIELD,
                self::WIDTH => [self::INT, 2000, 15000, 8000],
                self::DEPTH => [self::INT, 1500, 8000, 4000],
            ],
            self::BOX => [...self::POSITION, ...self::TURN_FIELD,
                self::Y => [self::INT, 0, 20000, 0],
                self::WIDTH => [self::INT, 50, 30000, 1000],
                self::HEIGHT => [self::INT, 50, 30000, 1000],
                self::DEPTH => [self::INT, 50, 30000, 1000],
                ...$color('#9a9a95'),
            ],
            self::CYLINDER => [...self::POSITION,
                self::Y => [self::INT, 0, 20000, 0],
                self::DIAMETER => [self::INT, 50, 20000, 1000],
                self::HEIGHT => [self::INT, 50, 30000, 1000],
                ...$color('#9a9a95'),
            ],
            self::PRISM => [
                self::POINTS => [self::OUTLINE, 3],
                self::Y => [self::INT, 0, 20000, 0],
                self::HEIGHT => [self::INT, 50, 30000, 1000],
                ...$color('#9a9a95'),
            ],
            self::PLOT => [self::POINTS => [self::OUTLINE, 3], self::SURFACE => [self::ENUM, self::SURFACES, self::SURFACE_ASPHALT]],
            self::BUILDING => [
                self::POINTS => [self::OUTLINE, 3],
                self::HEIGHT => [self::INT, 2000, 80000, 9000],
                self::USE => [self::ENUM, BuildingType::values(), BuildingType::Other->value],
                self::FACADE => [self::ENUM, Facade::values(), Facade::Plaster->value],
                // storeys above ground (cadastre): the façade's window rows follow them; 0 when unknown
                self::STOREYS => [self::INT, 0, 60, 0],
                self::PARTS => [self::BLOCKS],
                self::LOOK => [self::ENUM, [self::LOOK_DATA, self::LOOK_USER], self::LOOK_DATA],
                ...$color('#e9e2d6'),
                self::ROOF => [self::ENUM, Building::ROOFS, Building::ROOF_FLAT],
                // degrees; 0 when unknown (drawn at a usual pitch)
                self::PITCH => [self::INT, 0, 60, 0],
                self::ROOF_COLOR => [self::HEX, '#8d8a84'],
            ],
            self::ROAD => [
                self::POINTS => [self::OUTLINE, 2],
                self::WIDTH => [self::INT, 1000, 30000, 6000],
                self::KIND => [self::ENUM, self::ROAD_KINDS, self::ROAD_CARRIAGEWAY],
            ],
            self::NEWTREE => [...self::POSITION, self::HEIGHT => [self::INT, 3000, 25000, 9000]],
            self::RAINGARDEN => [self::POINTS => [self::OUTLINE, 3], self::STORAGE => [self::INT, 100, 600, 300]],
            self::PERMEABLE => [self::POINTS => [self::OUTLINE, 3], self::KIND => [self::ENUM, self::PERMEABLE_KINDS, self::KIND_PAVERS]],
            self::PLANTING => [self::POINTS => [self::OUTLINE, 3]],
            self::SHADESAIL => [self::POINTS => [self::OUTLINE, 3], self::Y => [self::INT, 2200, 6000, 3200], ...$color('#e8c872')],
            default => [],
        };
    }
}
