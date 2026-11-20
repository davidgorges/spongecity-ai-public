<?php

declare(strict_types=1);

namespace App\SpongeCity\Chat;

/**
 * The agent's MCP server listed no tools (its child process, bin/console mcp:server, did not start):
 * without them the model only writes tool calls as text, so the turn is not run.
 */
final class PlanningToolsUnavailable extends \RuntimeException
{
    public static function fromServer(): self
    {
        return new self('The MCP server listed no tools: bin/console mcp:server, which the agent starts over stdio, did not answer. The reason is logged as "Failed to list the tools of MCP server"; app:doctor checks it.');
    }
}
