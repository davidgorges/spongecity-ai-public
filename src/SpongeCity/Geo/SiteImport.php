<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo;

final readonly class SiteImport
{
    public function __construct(
        public string $siteJson,
        public bool $plotIsFallback,
        public int $buildings,
        public int $trees,
        public float $plotSquareMetres,
    ) {
    }
}
