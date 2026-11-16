<?php

declare(strict_types=1);

namespace Core\Configurator\Assembly;

/**
 * Geometry of a part in millimetres; the scene renderer has a builder per type().
 */
interface Shape
{
    public function type(): string;

    /**
     * @return array<string, mixed> type-specific geometry data, in millimetres
     */
    public function toArray(): array;
}
