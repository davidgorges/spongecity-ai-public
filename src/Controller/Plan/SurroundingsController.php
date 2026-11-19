<?php

declare(strict_types=1);

namespace App\Controller\Plan;

use App\SpongeCity\Geo\SurroundingsLoader;
use App\SpongeCity\Plan\Plan;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The real buildings and streets around a plan's site, for its 3D view (fetched once
 * by configurator_viewer_controller.js). Imported on first request when the plan
 * has none yet; empty when the map service is unavailable.
 */
final class SurroundingsController extends AbstractController
{
    public const string ROUTE = 'plan_surroundings';
    private const int TIME_LIMIT_SECONDS = 90;

    public function __construct(
        private readonly SurroundingsLoader $surroundings,
    ) {
    }

    #[Route('/plan/{id}/surroundings', name: self::ROUTE, methods: ['GET'])]
    #[Cache(maxage: 300, public: false)]
    public function __invoke(Plan $plan): JsonResponse
    {
        // the first request of an older plan imports them (the view asks in the background)
        set_time_limit(self::TIME_LIMIT_SECONDS);

        return $this->json($this->surroundings->load($plan)->toArray());
    }
}
