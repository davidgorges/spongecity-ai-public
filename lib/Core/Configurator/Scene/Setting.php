<?php

declare(strict_types=1);

namespace Core\Configurator\Scene;

/**
 * Where the viewer shows a product (the scene's `setting`): the environment
 * it builds around it, its moods and photo-mode skies.
 */
enum Setting: string
{
    /** sky, sun, lawn, a garden and hills; day, sunset and evening (outdoor_environment.js) */
    case Garden = 'garden';
    /** a seamless light floor under soft studio lights, one mood (studio_environment.js) */
    case Studio = 'studio';
}
