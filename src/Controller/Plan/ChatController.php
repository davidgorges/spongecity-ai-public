<?php

declare(strict_types=1);

namespace App\Controller\Plan;

use App\SpongeCity\Chat\PlanAssistant;
use App\SpongeCity\Plan\PlanStore;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ChatController extends AbstractController
{
    public const string ROUTE = 'plan_chat';
    public const string FIELD_MESSAGE = 'message';
    public const string KEY_REPLY = 'reply';
    public const string KEY_ERROR = 'error';
    private const int MAX_MESSAGE_LENGTH = 2000;

    private const int TIME_LIMIT_SECONDS = 180;

    public function __construct(
        private readonly PlanStore $plans,
        private readonly PlanAssistant $assistant,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/plan/{id}/chat', name: self::ROUTE, methods: ['POST'])]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        if (null === $this->plans->find($id)) {
            throw $this->createNotFoundException();
        }
        $message = trim((string) $request->getPayload()->get(self::FIELD_MESSAGE, ''));
        if ('' === $message || mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            return new JsonResponse([self::KEY_ERROR => 'plan.chat.invalid'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // one turn of the agent is several model calls with tools in between (40–60 s when it plans
        // interventions): longer than PHP's default limit for a request
        set_time_limit(self::TIME_LIMIT_SECONDS);
        try {
            return new JsonResponse([self::KEY_REPLY => $this->assistant->ask($id, $message)]);
        } catch (\Throwable $exception) {
            $this->logger->error('The planning agent failed', ['plan' => $id, 'exception' => $exception]);

            return new JsonResponse([self::KEY_ERROR => 'plan.chat.failed'], Response::HTTP_BAD_GATEWAY);
        }
    }
}
