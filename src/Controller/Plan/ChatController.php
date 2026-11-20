<?php

declare(strict_types=1);

namespace App\Controller\Plan;

use App\SpongeCity\Chat\AskPlanningAgent;
use App\SpongeCity\Plan\Plan;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Hands the person's message to the planning agent in a worker and answers at once: a turn takes
 * a minute or more, longer than a web request may. The reply arrives through Mercure (ChatStream).
 */
final class ChatController extends AbstractController
{
    public const string ROUTE = 'plan_chat';

    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/plan/{id}/chat', name: self::ROUTE, methods: ['POST'])]
    public function __invoke(Plan $plan, #[MapRequestPayload] ChatMessage $chat, Request $request): JsonResponse
    {
        $this->bus->dispatch(new AskPlanningAgent($plan->id, trim($chat->message), $request->getLocale()));

        return new JsonResponse(null, Response::HTTP_ACCEPTED);
    }
}
