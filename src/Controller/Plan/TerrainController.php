<?php

declare(strict_types=1);

namespace App\Controller\Plan;

use App\SpongeCity\Plan\Plan;
use App\SpongeCity\Terrain\TerrainStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The ground of a plan's site for its 3D view (fetched once by configurator_viewer_controller.js):
 * height grids relative to the plan's origin. 204 when the plan has none (outside Hamburg,
 * older plans): the view stays flat.
 */
final class TerrainController extends AbstractController
{
    public const string ROUTE = 'plan_terrain';

    public function __construct(
        private readonly TerrainStore $terrains,
    ) {
    }

    #[Route('/plan/{id}/terrain', name: self::ROUTE, methods: ['GET'])]
    #[Cache(maxage: 300, public: false)]
    public function __invoke(Plan $plan): JsonResponse
    {
        $terrain = $this->terrains->find($plan->id);

        return null === $terrain ? new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT) : $this->json($terrain->toArray());
    }
}
