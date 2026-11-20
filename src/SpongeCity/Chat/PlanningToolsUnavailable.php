<?php

declare(strict_types=1);

namespace App\SpongeCity\Chat;

/**
 * The agent's MCP server listed no tools (unreachable, or it refused the host it was called by):
 * without them the model only writes tool calls as text, so the turn is not run.
 */
final class PlanningToolsUnavailable extends \RuntimeException
{
    public static function fromServer(): self
    {
        return new self('The MCP server listed no tools: check MCP_SERVER_URL, and that its host is in MCP_ALLOWED_HOSTS. The reason is logged as "Failed to list the tools of MCP server".');
    }
}
