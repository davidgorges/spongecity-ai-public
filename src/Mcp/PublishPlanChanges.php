<?php

declare(strict_types=1);

namespace App\Mcp;

use App\SpongeCity\Plan\PlanUpdates;
use Mcp\Capability\Registry;
use Mcp\Event\ResponseEvent;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * After a tool changed a plan, tells the pages showing it (PlanUpdates), so the 3D view follows
 * every step of an agent: the in-app one (over stdio) and one a person connected (over HTTP).
 * A tool changes a plan when it is not marked read-only (its #[McpTool] annotations) and takes
 * the plan's id; a call that failed changed nothing.
 */
final readonly class PublishPlanChanges
{
    /** the argument a tool takes the plan's id in: the tools' `string $planId` parameter */
    public const string PLAN_ARGUMENT = 'planId';

    public function __construct(
        #[Autowire(service: 'mcp.server.default.registry')]
        private Registry $registry,
        private PlanUpdates $updates,
        private LoggerInterface $logger,
    ) {
    }

    #[AsEventListener]
    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request instanceof CallToolRequest || !\is_string($planId = $request->arguments[self::PLAN_ARGUMENT] ?? null)) {
            return;
        }
        $result = $event->getResponse()->result;
        if (($result instanceof CallToolResult && $result->isError) || !$this->registry->hasTool($request->name)) {
            return;
        }
        if (true === $this->registry->getTool($request->name)->tool->annotations?->readOnlyHint) {
            return;
        }

        try {
            $this->updates->changed($planId, $request->name);
        } catch (\Throwable $exception) {
            // the change is made; a page that misses it catches up on its next render
            $this->logger->warning('Could not tell the pages that a plan changed', ['plan' => $planId, 'tool' => $request->name, 'exception' => $exception]);
        }
    }
}
