<?php

declare(strict_types=1);

namespace App\Controller\Plan;

use App\SpongeCity\Chat\PlanAssistant;
use App\SpongeCity\Plan\PlanStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ShowPlanController extends AbstractController
{
    public const string ROUTE = 'plan_show';

    public function __construct(
        private readonly PlanStore $plans,
        private readonly PlanAssistant $assistant,
    ) {
    }

    #[Route('/plan/{id}', name: self::ROUTE, methods: ['GET'])]
    public function __invoke(string $id): Response
    {
        $plan = $this->plans->find($id) ?? throw $this->createNotFoundException();

        return $this->render('plan/show.html.twig', ['plan' => $plan, 'history' => $this->assistant->history($id)]);
    }
}
