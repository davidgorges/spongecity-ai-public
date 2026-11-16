<?php

declare(strict_types=1);

namespace App\Controller\Plan;

use App\SpongeCity\Conditions\SiteConditionsStore;
use App\SpongeCity\Plan\PlanStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What official data says about a plan's ground (infiltration potential) and the sources
 * of its layers, for the overlay and the attribution of the 3D view (fetched once by
 * configurator_viewer_controller.js). Empty outside Hamburg and for older plans.
 */
final class ConditionsController extends AbstractController
{
    public const string ROUTE = 'plan_conditions';
    private const int MAX_AGE_SECONDS = 300;

    public function __construct(
        private readonly PlanStore $plans,
        private readonly SiteConditionsStore $conditions,
    ) {
    }

    #[Route('/plan/{id}/conditions', name: self::ROUTE, methods: ['GET'])]
    public function __invoke(string $id): JsonResponse
    {
        $plan = $this->plans->find($id) ?? throw $this->createNotFoundException();
        $response = $this->json($this->conditions->get($plan->id)->toArray());
        $response->setPrivate();
        $response->setMaxAge(self::MAX_AGE_SECONDS);

        return $response;
    }
}
