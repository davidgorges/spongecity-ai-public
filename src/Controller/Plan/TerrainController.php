<?php

declare(strict_types=1);

namespace App\Controller\Plan;

use App\SpongeCity\Plan\PlanStore;
use App\SpongeCity\Terrain\TerrainStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The ground of a plan's site for its 3D view (fetched once by configurator_viewer_controller.js):
 * height grids relative to the plan's origin. 204 when the plan has none (outside Hamburg,
 * older plans): the view stays flat.
 */
final class TerrainController extends AbstractController
{
    public const string ROUTE = 'plan_terrain';
    private const int MAX_AGE_SECONDS = 300;

    public function __construct(
        private readonly PlanStore $plans,
        private readonly TerrainStore $terrains,
    ) {
    }

    #[Route('/plan/{id}/terrain', name: self::ROUTE, methods: ['GET'])]
    public function __invoke(string $id): JsonResponse
    {
        $plan = $this->plans->find($id) ?? throw $this->createNotFoundException();
        $terrain = $this->terrains->find($plan->id);
        $response = null === $terrain ? new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT) : $this->json($terrain->toArray());
        $response->setPrivate();
        $response->setMaxAge(self::MAX_AGE_SECONDS);

        return $response;
    }
}
