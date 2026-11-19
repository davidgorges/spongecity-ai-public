<?php

declare(strict_types=1);

namespace App\Configurator;

use Core\Configurator\Scene\GardenModels;
use Core\Configurator\Scene\ModelCatalog;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Model libraries are GLB files in assets/configurator/models/, served (and
 * versioned) by AssetMapper, or built in the browser (procedural libraries,
 * assets/configurator/procedural_models.js) when no GLB of that name exists.
 */
#[AsAlias(ModelCatalog::class)]
final readonly class AssetMapperModelCatalog implements ModelCatalog
{
    public const string DIRECTORY = 'configurator/models/';
    public const string EXTENSION = '.glb';
    public const string PROCEDURAL_PREFIX = 'procedural:';
    /** libraries assets/configurator/procedural_models.js can build */
    public const array PROCEDURAL_LIBRARIES = [GardenModels::FURNITURE];

    public function __construct(private AssetMapperInterface $assetMapper)
    {
    }

    public function url(string $library): ?string
    {
        $asset = $this->assetMapper->getAsset(self::DIRECTORY.$library.self::EXTENSION);
        if (null !== $asset) {
            return $asset->publicPath;
        }

        return in_array($library, self::PROCEDURAL_LIBRARIES, true) ? self::PROCEDURAL_PREFIX.$library : null;
    }
}
