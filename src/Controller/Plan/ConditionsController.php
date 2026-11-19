<?php

declare(strict_types=1);

namespace App\Controller\Plan;

use App\SpongeCity\Conditions\SiteConditionsStore;
use App\SpongeCity\Plan\Plan;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What official data says about a plan's ground (infiltration potential) and the sources
 * of its layers, for the overlay and the attribution of the 3D view (fetched once by
 * configurator_viewer_controller.js). Empty outside Hamburg and for older plans.
 */
final class ConditionsController extends AbstractController
{
    public const string ROUTE = 'plan_conditions';

    public function __construct(
        private readonly SiteConditionsStore $conditions,
    ) {
    }

    #[Route('/plan/{id}/conditions', name: self::ROUTE, methods: ['GET'])]
    #[Cache(maxage: 300, public: false)]
    public function __invoke(Plan $plan): JsonResponse
    {
        return $this->json($this->conditions->get($plan->id)->toArray());
    }
}
