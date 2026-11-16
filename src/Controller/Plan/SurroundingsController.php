<?php

declare(strict_types=1);

namespace App\Controller\Plan;

use App\SpongeCity\Geo\SurroundingsLoader;
use App\SpongeCity\Plan\PlanStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The real buildings and streets around a plan's site, for its 3D view (fetched once
 * by configurator_viewer_controller.js). Imported on first request when the plan
 * has none yet; empty when the map service is unavailable.
 */
final class SurroundingsController extends AbstractController
{
    public const string ROUTE = 'plan_surroundings';
    private const int MAX_AGE_SECONDS = 300;
    private const int TIME_LIMIT_SECONDS = 90;

    public function __construct(
        private readonly PlanStore $plans,
        private readonly SurroundingsLoader $surroundings,
    ) {
    }

    #[Route('/plan/{id}/surroundings', name: self::ROUTE, methods: ['GET'])]
    public function __invoke(string $id): JsonResponse
    {
        $plan = $this->plans->find($id) ?? throw $this->createNotFoundException();
        // the first request of an older plan imports them (the view asks in the background)
        set_time_limit(self::TIME_LIMIT_SECONDS);
        $response = $this->json($this->surroundings->load($plan)->toArray());
        $response->setPrivate();
        $response->setMaxAge(self::MAX_AGE_SECONDS);

        return $response;
    }
}
