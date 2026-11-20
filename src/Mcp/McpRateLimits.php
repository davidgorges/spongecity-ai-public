<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Exception\ToolCallException;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * /mcp is open to anyone, like the plan page (the plan's id is the key), so it is rate limited per
 * client address (config/packages/rate_limiter.yaml): every request, and more tightly the tools that
 * cost the public services or the server real work. The in-app agent reaches the tools over stdio,
 * without an HTTP request, and is not limited.
 */
final readonly class McpRateLimits
{
    public function __construct(
        #[Target('mcp_requests')]
        private RateLimiterFactoryInterface $requests,
        #[Target('mcp_expensive_tools')]
        private RateLimiterFactoryInterface $expensiveTools,
        private RequestStack $requestStack,
    ) {
    }

    #[AsEventListener(KernelEvents::REQUEST)]
    public function limitRequests(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || McpEndpoint::ROUTE !== $request->attributes->get('_route')) {
            return;
        }
        $limit = $this->requests->create($request->getClientIp() ?? '')->consume();
        if (!$limit->isAccepted()) {
            $event->setResponse(new JsonResponse(['error' => 'Too many requests'], Response::HTTP_TOO_MANY_REQUESTS, [
                'Retry-After' => max(1, $limit->getRetryAfter()->getTimestamp() - time()),
            ]));
        }
    }

    /**
     * Called by an expensive tool before it starts: refuses it, as the tool's error the agent reads,
     * when its client used up its share.
     *
     * @throws ToolCallException
     */
    public function spendOnExpensiveTool(string $tool): void
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request) {
            return;
        }
        $limit = $this->expensiveTools->create($request->getClientIp() ?? '')->consume();
        if (!$limit->isAccepted()) {
            throw new ToolCallException(sprintf('"%s" was used too often from this address. Try again after %s (UTC).', $tool, $limit->getRetryAfter()->format('H:i')));
        }
    }
}
