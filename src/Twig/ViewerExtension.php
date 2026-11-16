<?php

declare(strict_types=1);

namespace App\Twig;

use Core\Configurator\Scene\GardenModels;
use Core\Configurator\Scene\ProceduralTexture;
use Symfony\Component\Asset\Packages;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The asset URLs the 3D viewer (assets/configurator/) needs next to the scene itself.
 */
final class ViewerExtension extends AbstractExtension
{
    public const string FUNCTION_SKIES = 'configurator_skies';
    public const string FUNCTION_TEXTURES = 'configurator_textures';
    public const string FUNCTION_MODELS = 'configurator_environment_models';

    private const array MOODS = ['day', 'sunset', 'evening', 'studio'];
    private const string SKY_PATH = 'configurator/hdri/sky_%s.hdr';
    private const string MODEL_PATH = 'configurator/models/%s.glb';
    private const string TEXTURE_PATH = 'configurator/textures/%s_%s.webp';
    private const array PHOTO_TEXTURES = [ProceduralTexture::Plaster->value, ProceduralTexture::Paving->value, ProceduralTexture::RoofTiles->value];
    private const array PHOTO_TEXTURE_MAPS = ['color', 'normal', 'rough'];

    public function __construct(private readonly Packages $packages)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction(self::FUNCTION_SKIES, $this->skies(...)),
            new TwigFunction(self::FUNCTION_TEXTURES, $this->textures(...)),
            new TwigFunction(self::FUNCTION_MODELS, $this->environmentModels(...)),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function skies(): array
    {
        $skies = [];
        foreach (self::MOODS as $mood) {
            $skies[$mood] = $this->packages->getUrl(sprintf(self::SKY_PATH, $mood));
        }

        return $skies;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function textures(): array
    {
        $textures = [];
        foreach (self::PHOTO_TEXTURES as $texture) {
            foreach (self::PHOTO_TEXTURE_MAPS as $map) {
                $textures[$texture][$map] = $this->packages->getUrl(sprintf(self::TEXTURE_PATH, $texture, $map));
            }
        }

        return $textures;
    }

    /**
     * @return array<string, string>
     */
    public function environmentModels(): array
    {
        return [GardenModels::TREES => $this->packages->getUrl(sprintf(self::MODEL_PATH, GardenModels::TREES))];
    }
}
