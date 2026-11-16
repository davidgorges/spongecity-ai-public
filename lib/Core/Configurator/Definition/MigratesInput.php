<?php

declare(strict_types=1);

namespace Core\Configurator\Definition;

/**
 * A product definition whose parameters changed: it rewrites raw input in an
 * older shape (bookmarked URLs, stored requests) into the current parameters
 * before it is resolved, so old links keep working.
 */
interface MigratesInput
{
    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    public function migrateInput(array $raw): array;
}
