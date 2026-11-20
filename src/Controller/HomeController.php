<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Plan\ShowPlanController;
use App\SpongeCity\Import\PlanImporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    public const string ROUTE = 'home';
    public const string ROUTE_START = 'home_start';
    public const string FLASH_ERROR = 'error';
    /** the plans opened in this browser (recent_plans_controller.js) */
    public const string RECENT_PLANS_STORAGE_KEY = 'spongecity-recent-plans';

    public function __construct(
        private readonly PlanImporter $importer,
    ) {
    }

    #[Route('/', name: self::ROUTE, methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('home/index.html.twig', ['schools' => ExampleSchool::cases()]);
    }

    /** a plan for the place asked for; its import runs in a worker while the plan page shows progress */
    #[Route('/', name: self::ROUTE_START, methods: ['POST'])]
    public function start(#[MapRequestPayload] PlaceSearch $search): Response
    {
        $plan = $this->importer->start(trim($search->place));
        if (null === $plan) {
            $this->addFlash(self::FLASH_ERROR, 'home.error.not_found');

            return $this->redirectToRoute(self::ROUTE);
        }

        return $this->redirectToRoute(ShowPlanController::ROUTE, ['id' => $plan->id]);
    }
}
