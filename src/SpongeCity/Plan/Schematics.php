<?php

declare(strict_types=1);

namespace App\SpongeCity\Plan;

use Core\Configurator\Site\SiteObject as O;

/**
 * Which schematic (an isometric cutaway of how a measure is built) the object panel shows for an
 * intervention: one per type, and per variant where it is built differently (a rain garden with a
 * trench, a tree with a tree trench, a permeable surface by its kind, a green roof by its kind).
 * The images lie in assets/images/schematics/, named as name() returns.
 */
final class Schematics
{
    /** where the images lie, for the asset() function */
    public const string DIRECTORY = 'images/schematics/';
    public const string EXTENSION = '.webp';
    /** every image's size in pixels, 3:2, for the page to reserve its room */
    public const int WIDTH = 768;
    public const int HEIGHT = 516;
    /** a variant's name: the type, a separator, the variant */
    private const string SEPARATOR = '-';
    private const string TRENCH = 'trench';

    private function __construct()
    {
    }

    /**
     * The schematic's name for an intervention, null for an object that has none (the city around it).
     *
     * @param array<string, mixed> $object
     */
    public static function name(array $object): ?string
    {
        $type = is_string($object[O::TYPE] ?? null) ? $object[O::TYPE] : '';
        if (!in_array($type, O::INTERVENTIONS, true)) {
            return null;
        }
        $variant = match ($type) {
            O::RAINGARDEN => (int) ($object[O::TRENCH] ?? 0) > 0 ? self::TRENCH : null,
            O::NEWTREE => (int) ($object[O::TRENCH_VOLUME] ?? 0) > 0 ? self::TRENCH : null,
            O::PERMEABLE => in_array($object[O::KIND] ?? null, O::PERMEABLE_KINDS, true) ? (string) $object[O::KIND] : O::KIND_PAVERS,
            O::GREENROOF => in_array($object[O::KIND] ?? null, O::GREENROOF_KINDS, true) ? (string) $object[O::KIND] : O::KIND_EXTENSIVE,
            default => null,
        };

        return null === $variant ? $type : $type.self::SEPARATOR.str_replace('_', self::SEPARATOR, $variant);
    }

    /**
     * The path asset() takes to an intervention's schematic, null for an object that has none.
     *
     * @param array<string, mixed> $object
     */
    public static function of(array $object): ?string
    {
        $name = self::name($object);

        return null === $name ? null : self::path($name);
    }

    /** the path asset() takes */
    public static function path(string $name): string
    {
        return self::DIRECTORY.$name.self::EXTENSION;
    }

    /**
     * Every schematic there is, so a test can tell that none is missing.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $names = [];
        foreach (O::INTERVENTIONS as $type) {
            $variants = match ($type) {
                O::RAINGARDEN => [[], [O::TRENCH => 500]],
                O::NEWTREE => [[], [O::TRENCH_VOLUME => 12]],
                O::PERMEABLE => array_map(static fn (string $kind): array => [O::KIND => $kind], O::PERMEABLE_KINDS),
                O::GREENROOF => array_map(static fn (string $kind): array => [O::KIND => $kind], O::GREENROOF_KINDS),
                default => [[]],
            };
            foreach ($variants as $fields) {
                $names[] = (string) self::name([O::TYPE => $type, ...$fields]);
            }
        }

        return $names;
    }
}
