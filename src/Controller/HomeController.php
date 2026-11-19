<?php

declare(strict_types=1);

namespace App\Controller;

use App\SpongeCity\Geo\NominatimGeocoder;
use App\Controller\Plan\ShowPlanController;
use App\SpongeCity\Import\PlanImporter;
use App\SpongeCity\Plan\Plan;
use App\SpongeCity\Plan\PlanStatus;
use App\SpongeCity\Plan\PlanStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    public const string ROUTE = 'home';
    public const string FIELD_PLACE = 'place';
    public const string FLASH_ERROR = 'error';
    /** the plans opened in this browser (recent_plans_controller.js) */
    public const string RECENT_PLANS_STORAGE_KEY = 'spongecity-recent-plans';

    public function __construct(
        private readonly NominatimGeocoder $geocoder,
        private readonly PlanStore $plans,
        private readonly PlanImporter $importer,
    ) {
    }

    #[Route('/', name: self::ROUTE, methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $place = trim((string) $request->getPayload()->get(self::FIELD_PLACE, ''));
            $plan = '' === $place ? null : $this->start($place);
            if (null !== $plan) {
                return $this->redirectToRoute(ShowPlanController::ROUTE, ['id' => $plan->id]);
            }
            $this->addFlash(self::FLASH_ERROR, 'home.error.not_found');
        }

        return $this->render('home/index.html.twig', ['examples' => ['home.example.schoolyard', 'home.example.courtyard', 'home.example.square']]);
    }

    private function start(string $place): ?Plan
    {
        $hits = $this->geocoder->search($place);
        if ([] === $hits) {
            return null;
        }
        $centre = $hits[0];
        $plan = $this->plans->create($centre->label, $centre->latitude, $centre->longitude, PlanStatus::New);
        // the slow part, OpenStreetMap, runs in a worker: the plan page shows progress until it is ready
        $this->importer->request($plan, $centre);

        return $plan;
    }
}
