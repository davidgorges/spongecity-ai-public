<?php

declare(strict_types=1);

namespace Core\Configurator\Site;

use Core\Configurator\Parameter\ParameterGroup;
use Core\Configurator\Parameter\StructuredParameter;
use Core\Configurator\Parameter\StructuredValue;
use Core\Configurator\Site\SiteObject as O;

/**
 * The customer's surroundings as a structured value: their house, garage,
 * hedges, walls, trees, paving, pool, and boxes, cylinders and prisms for
 * anything else, so the product is seen where it will stand. Scenery only:
 * never priced, never on the bill of materials. An AI agent fills it from a
 * photo or a description (WebMCP tools); the form lists what is there.
 *
 * Canonical value: {"objects": [{id, type, …fields of SiteObject::fields()}]}.
 * normalize() fills in defaults, clamps every number, drops what it cannot
 * read (an unknown type, an outline with too few points) and keeps at most
 * MAX_OBJECTS. Ids are kept when valid and unique, else given ("o1", "o2", …).
 */
final readonly class SiteType implements StructuredValue
{
    public const string PARAMETER = 'site';
    public const string LABEL = 'configurator.site';
    public const string EDITOR = 'site';
    public const string OBJECTS = 'objects';
    /** context from the map (plot, buildings, trees, roads) and room for the agent's interventions */
    public const int MAX_OBJECTS = 200;
    private const string ID_PATTERN = '/^[A-Za-z0-9_-]{1,24}$/';
    private const string ID_PREFIX = 'o';
    private const string HEX_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    /**
     * The site as a product's last parameter: add it to parameters() to show
     * the product in the customer's surroundings.
     */
    public static function parameter(): StructuredParameter
    {
        return new StructuredParameter(self::PARAMETER, self::LABEL, new self(), ParameterGroup::SITE);
    }

    public function defaultValue(): string
    {
        return self::encode([]);
    }

    public function normalize(mixed $raw): string
    {
        $objects = [];
        foreach (array_slice(self::rawObjects($raw), 0, self::MAX_OBJECTS) as $object) {
            $normalized = is_array($object) ? self::object($object) : null;
            if (null !== $normalized) {
                $objects[] = $normalized;
            }
        }

        return self::encode(self::withIds($objects));
    }

    /**
     * The input already was its result when every object kept every field
     * it was given (defaults and ids filled in are no correction).
     */
    public function isSameInput(mixed $raw, string $resolved): bool
    {
        $given = self::rawObjects($raw);
        $result = self::rawObjects($resolved);
        if (count($given) !== count($result)) {
            return false;
        }
        foreach ($given as $i => $object) {
            if (!is_array($object) || !is_array($result[$i] ?? null)) {
                return false;
            }
            foreach ($object as $key => $value) {
                if (O::ID !== $key && json_encode($value) !== json_encode($result[$i][$key] ?? null)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function editor(): string
    {
        return self::EDITOR;
    }

    /**
     * For the editor's list: every object with its main measures in
     * millimetres (width and depth, length and height, …).
     *
     * @return list<array{id: string, type: string, label: string|null, measures: list<int>}>
     */
    public function rows(string $value): array
    {
        $rows = [];
        foreach (Site::fromJson($value)->objects as $object) {
            $number = static fn (string $key): int => is_numeric($object[$key] ?? null) ? (int) $object[$key] : 0;
            $type = is_string($object[O::TYPE]) ? $object[O::TYPE] : '';
            $rows[] = [
                'id' => is_string($object[O::ID] ?? null) ? $object[O::ID] : '',
                'type' => $type,
                'label' => is_string($object[O::LABEL] ?? null) ? $object[O::LABEL] : null,
                'measures' => match ($type) {
                    O::HOUSE, O::OUTBUILDING, O::POOL => [$number(O::WIDTH), $number(O::DEPTH)],
                    O::BOX => [$number(O::WIDTH), $number(O::DEPTH), $number(O::HEIGHT)],
                    O::HEDGE, O::WALL => [(int) round(self::length(Site::points($object))), $number(O::HEIGHT)],
                    O::CYLINDER => [$number(O::DIAMETER), $number(O::HEIGHT)],
                    O::TREE, O::PRISM => [$number(O::HEIGHT)],
                    default => [],
                },
            ];
        }

        return $rows;
    }

    /**
     * For the summary: how many objects of each type, in the order of the types.
     *
     * @return array<string, int>
     */
    public function counts(string $value): array
    {
        $counts = array_count_values(array_column($this->rows($value), 'type'));

        return array_filter(array_map(static fn (string $type): int => $counts[$type] ?? 0, array_combine(O::TYPES, O::TYPES)));
    }

    /**
     * @ignore-large-array-rule a JSON Schema document
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'The customer\'s surroundings around the product (scenery only, never priced). '
                .'Plan coordinates in millimetres, the same as the product\'s (get_configuration lists them under "plan"): x to the right seen from the street, z towards the street; turn in degrees, positive turns the front to the right. '
                .'Model only what matters for the view: the house, outbuildings, hedges, walls, big trees, paving. Use house/outbuilding/hedge/wall/tree/paving/pool where they fit, box/cylinder/prism for anything else. '
                .'Sizes estimated from a photo are often 20 to 30 % off: ask the customer for one known measure (the house\'s width, a door) and scale from it.',
            'properties' => [
                self::OBJECTS => ['type' => 'array', 'maxItems' => self::MAX_OBJECTS, 'items' => self::objectSchema()],
            ],
            'required' => [self::OBJECTS],
            'additionalProperties' => false,
        ];
    }

    /**
     * Schema of one object: a choice of the types, each with its fields.
     *
     * @return array<string, mixed>
     */
    public static function objectSchema(bool $partial = false): array
    {
        return ['oneOf' => array_map(static fn (string $type): array => self::typeSchema($type, $partial), O::TYPES)];
    }

    /**
     * @param array<array-key, mixed> $object
     *
     * @return array<string, mixed>|null the object with every field, null if it cannot be read
     */
    public static function object(array $object): ?array
    {
        $type = $object[O::TYPE] ?? null;
        if (!is_string($type) || !in_array($type, O::TYPES, true)) {
            return null;
        }
        $normalized = [O::ID => is_string($object[O::ID] ?? null) ? $object[O::ID] : null, O::TYPE => $type];
        foreach (O::fields($type) as $name => $field) {
            $value = self::field($field, $object[$name] ?? null);
            if (null === $value) {
                return null;
            }
            $normalized[$name] = $value;
        }
        $label = $object[O::LABEL] ?? null;
        $normalized[O::LABEL] = is_string($label) && '' !== trim($label) ? mb_substr(trim($label), 0, O::MAX_LABEL) : null;
        // set from the terrain (not part of the schema: nobody types it in)
        $ground = $object[O::GROUND] ?? null;
        $normalized[O::GROUND] = is_numeric($ground) ? max(-O::MAX_GROUND, min(O::MAX_GROUND, (int) round((float) $ground))) : 0;
        // a building's own roof from the 3D model (not part of the schema either: the import sets it)
        if (O::BUILDING === $type) {
            $model = self::model($object[O::MODEL] ?? null);
            if (null !== $model) {
                $normalized[O::MODEL] = $model;
            }
        }

        return $normalized;
    }

    /**
     * @param list<array<string, mixed>> $objects
     */
    public static function encode(array $objects): string
    {
        return json_encode([self::OBJECTS => $objects], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return list<mixed>
     */
    public static function rawObjects(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        $objects = is_array($raw) ? ($raw[self::OBJECTS] ?? null) : null;

        return is_array($objects) ? array_values($objects) : [];
    }

    /**
     * @param array<int, mixed> $field
     *
     * @return int|string|list<array{int, int}>|null null when the value cannot be used (an outline too short)
     */
    private static function field(array $field, mixed $value): int|string|array|null
    {
        $int = static fn (int $i): int => is_int($field[$i] ?? null) ? $field[$i] : 0;
        $text = static fn (int $i): string => is_string($field[$i] ?? null) ? $field[$i] : '';

        return match ($field[0]) {
            O::INT => is_numeric($value) ? max($int(1), min($int(2), (int) round((float) $value))) : $int(3),
            O::ENUM => is_string($value) && is_array($field[1]) && in_array($value, $field[1], true) ? $value : $text(2),
            O::HEX => is_string($value) && 1 === preg_match(self::HEX_PATTERN, $value) ? strtolower($value) : $text(1),
            O::OUTLINE => self::outline($value, $int(1)),
            O::BLOCKS => self::blocks($value),
            default => null,
        };
    }

    /**
     * Roof-level blocks: each an outline and a height (as a building's); unreadable ones are left out.
     *
     * @return list<array{points: list<array{int, int}>, height: int}>
     */
    private static function blocks(mixed $value): array
    {
        $blocks = [];
        $height = O::fields(O::BUILDING)[O::HEIGHT];
        foreach (is_array($value) ? array_slice($value, 0, O::MAX_PARTS) : [] as $block) {
            $points = is_array($block) ? self::outline($block[O::POINTS] ?? null, 3) : null;
            if (null !== $points && is_numeric($block[O::HEIGHT] ?? null)) {
                $blocks[] = [O::POINTS => $points, O::HEIGHT => max($height[1], min($height[2], (int) round((float) $block[O::HEIGHT])))];
            }
        }

        return $blocks;
    }

    /**
     * A building's model (SiteObject::MODEL): planar polygons of [x, y, z] millimetres within the
     * site's reach; unreadable polygons are left out, null when no roof is left.
     *
     * @return array{roofs: list<list<array{int, int, int}>>, walls: list<list<array{int, int, int}>>}|null
     */
    private static function model(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $height = O::fields(O::BUILDING)[O::HEIGHT][2];
        $polygons = static function (mixed $list) use ($height): array {
            $polygons = [];
            foreach (is_array($list) ? array_slice($list, 0, O::MAX_MODEL_SURFACES) : [] as $polygon) {
                $corners = [];
                foreach (is_array($polygon) ? array_slice($polygon, 0, O::MAX_MODEL_CORNERS) : [] as $corner) {
                    if (!is_array($corner) || !is_numeric($corner[0] ?? null) || !is_numeric($corner[1] ?? null) || !is_numeric($corner[2] ?? null)) {
                        continue 2;
                    }
                    $corners[] = [
                        max(-O::REACH, min(O::REACH, (int) round((float) $corner[0]))),
                        max(0, min($height, (int) round((float) $corner[1]))),
                        max(-O::REACH, min(O::REACH, (int) round((float) $corner[2]))),
                    ];
                }
                if (count($corners) >= 3) {
                    $polygons[] = $corners;
                }
            }

            return $polygons;
        };
        $roofs = $polygons($value[O::MODEL_ROOFS] ?? null);

        return [] === $roofs ? null : [O::MODEL_ROOFS => $roofs, O::MODEL_WALLS => $polygons($value[O::MODEL_WALLS] ?? null)];
    }

    /**
     * @param list<array{float, float}> $points
     */
    private static function length(array $points): float
    {
        $length = 0.0;
        for ($i = 1, $count = count($points); $i < $count; ++$i) {
            $length += hypot($points[$i][0] - $points[$i - 1][0], $points[$i][1] - $points[$i - 1][1]);
        }

        return $length;
    }

    /**
     * @return list<array{int, int}>|null
     */
    private static function outline(mixed $value, int $minimum): ?array
    {
        $points = [];
        foreach (is_array($value) ? array_slice($value, 0, O::MAX_POINTS) : [] as $point) {
            if (!is_array($point) || !is_numeric($point[0] ?? null) || !is_numeric($point[1] ?? null)) {
                continue;
            }
            $clamped = [
                max(-O::REACH, min(O::REACH, (int) round((float) $point[0]))),
                max(-O::REACH, min(O::REACH, (int) round((float) $point[1]))),
            ];
            if ($clamped !== end($points)) {
                $points[] = $clamped;
            }
        }

        return count($points) >= $minimum ? $points : null;
    }

    /**
     * @param list<array<string, mixed>> $objects
     *
     * @return list<array<string, mixed>>
     */
    private static function withIds(array $objects): array
    {
        $taken = [];
        foreach ($objects as $i => $object) {
            $id = $object[O::ID];
            if (!is_string($id) || 1 !== preg_match(self::ID_PATTERN, $id) || isset($taken[$id])) {
                $objects[$i][O::ID] = null;
                continue;
            }
            $taken[$id] = true;
        }
        $next = 1;
        foreach ($objects as $i => $object) {
            if (null === $object[O::ID]) {
                while (isset($taken[self::ID_PREFIX.$next])) {
                    ++$next;
                }
                $objects[$i][O::ID] = self::ID_PREFIX.$next;
                $taken[self::ID_PREFIX.$next] = true;
            }
        }

        return $objects;
    }

    /**
     * @return array<string, mixed>
     */
    private static function typeSchema(string $type, bool $partial): array
    {
        $properties = [
            O::TYPE => ['const' => $type],
            O::LABEL => ['type' => 'string', 'maxLength' => O::MAX_LABEL, 'description' => 'What the customer calls it ("Garage", "Nachbarhaus").'],
        ];
        foreach (O::fields($type) as $name => $field) {
            $properties[$name] = match ($field[0]) {
                O::INT => ['type' => 'integer', 'minimum' => $field[1], 'maximum' => $field[2], 'default' => $field[3]],
                O::ENUM => ['type' => 'string', 'enum' => $field[1], 'default' => $field[2]],
                O::HEX => ['type' => 'string', 'pattern' => '^#[0-9a-fA-F]{6}$', 'default' => $field[1]],
                O::BLOCKS => ['type' => 'array', 'maxItems' => O::MAX_PARTS, 'description' => 'Roof levels from the 3D building model: leave as they are.', 'items' => ['type' => 'object', 'properties' => [O::POINTS => ['type' => 'array'], O::HEIGHT => ['type' => 'integer']]]],
                default => ['type' => 'array', 'minItems' => $field[1], 'maxItems' => O::MAX_POINTS, 'items' => ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 2, 'maxItems' => 2], 'description' => 'Plan points [x, z] in millimetres.'],
            };
        }

        return [
            'type' => 'object',
            'description' => O::DESCRIPTIONS[$type],
            'properties' => $properties,
            'required' => $partial ? [] : [O::TYPE, ...array_keys(array_filter(O::fields($type), static fn (array $field): bool => O::OUTLINE === $field[0]))],
            'additionalProperties' => false,
        ];
    }
}
