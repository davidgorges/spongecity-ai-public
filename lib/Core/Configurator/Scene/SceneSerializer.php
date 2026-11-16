<?php

declare(strict_types=1);

namespace Core\Configurator\Scene;

use Core\Configurator\Assembly\Assembly;
use Core\Configurator\Assembly\Model;

/**
 * The JSON the browser renders (assets/configurator/scene_renderer.js):
 * metres instead of millimetres, only drawable parts, materials used by them,
 * models with the URL of their library (left out when it is not installed).
 */
final readonly class SceneSerializer
{
    private const float MM_TO_M = 0.001;

    public function __construct(private ModelCatalog $models)
    {
    }

    /**
     * @ignore-large-array-rule a part's entry mirrors the scene JSON contract (docs/configurator/scene-format.md)
     *
     * @param Setting $setting the environment the viewer builds (Evaluation::setting())
     *
     * @return array{setting: string, materials: array<string, array<string, string|float|bool|null>>, parts: list<array<array-key, mixed>>, environment: array<string, bool>|\stdClass, clear: list<list<float>>}
     */
    public function serialize(Assembly $assembly, MaterialLibrary $materials, Setting $setting = Setting::Studio): array
    {
        $parts = [];
        $used = [];
        foreach ($assembly as $part) {
            if (null === $part->shape) {
                continue;
            }
            $model = [];
            if ($part->shape instanceof Model) {
                $url = $this->models->url($part->shape->library);
                if (null === $url) {
                    continue;
                }
                $model = ['url' => $url];
            }
            if (null !== $part->material) {
                if (!$materials->has($part->material) && !isset($assembly->materials()[$part->material])) {
                    throw new \LogicException(sprintf('Part "%s" uses unknown material "%s".', $part->label, $part->material));
                }
                $used[$part->material] = true;
            }

            $parts[] = [
                'shape' => $part->shape->type(),
                ...$this->toMetres($part->shape->toArray()),
                ...$model,
                'position' => $this->vectorToMetres($part->position->toArray()),
                'rotation' => $part->rotation->toArray(),
                'material' => $part->material,
                'sku' => $part->sku,
                'label' => $part->label,
                'decor' => $part->decor,
                'draped' => $part->draped,
            ];
        }

        $library = [];
        foreach ([...$materials->all(), ...$assembly->materials()] as $key => $material) {
            if (isset($used[$key])) {
                $library[$key] = $material->toArray();
            }
        }

        $environment = $assembly->environmentHints();

        // an object in JSON even when empty
        // areas the viewer's garden leaves free (the customer's site), metres
        $clear = array_map(fn (array $area): array => $this->vectorToMetres($area), $assembly->clearAreas());

        return ['setting' => $setting->value, 'clear' => $clear, 'materials' => $library, 'parts' => $parts, 'environment' => [] === $environment ? new \stdClass() : $environment];
    }

    /**
     * Shape data in metres: every number (nested lists included) is a length
     * in millimetres; strings (library, variant) stay as they are.
     *
     * @param array<array-key, mixed> $geometry
     *
     * @return array<array-key, mixed>
     */
    private function toMetres(array $geometry): array
    {
        return array_map(
            fn (mixed $value): mixed => match (true) {
                is_array($value) => $this->toMetres($value),
                is_int($value), is_float($value) => round($value * self::MM_TO_M, 4),
                default => $value,
            },
            $geometry,
        );
    }

    /**
     * @param array<array-key, float> $vector
     *
     * @return list<float>
     */
    private function vectorToMetres(array $vector): array
    {
        return array_values(array_map(static fn (float $v): float => round($v * self::MM_TO_M, 4), $vector));
    }
}
