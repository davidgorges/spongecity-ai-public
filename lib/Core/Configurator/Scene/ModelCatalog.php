<?php

declare(strict_types=1);

namespace Core\Configurator\Scene;

/**
 * Where the browser loads a model library from. Libraries that are not
 * installed resolve to null, and their parts are left out of the scene, so
 * products work before (or without) their scenery models.
 */
interface ModelCatalog
{
    public function url(string $library): ?string;
}
