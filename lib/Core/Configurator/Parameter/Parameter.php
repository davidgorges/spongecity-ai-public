<?php

declare(strict_types=1);

namespace Core\Configurator\Parameter;

/**
 * One input of a product: a dimension, a choice or a switch.
 *
 * A parameter never rejects a value: resolve() turns whatever came from the
 * browser into the closest allowed value, so the configuration is always
 * buildable and the customer sees the correction instead of an error.
 */
interface Parameter
{
    public function name(): string;

    public function label(): string;

    public function group(): string;

    public function resolve(mixed $raw): int|string|bool;

    public function defaultValue(): int|string|bool;
}
