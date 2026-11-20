<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * The MCP server's HTTP endpoint (config/packages/mcp.yaml, server "default", path /mcp): where an
 * agent a person connects reaches the tools. The plan page shows its URL (url(ROUTE)).
 */
final class McpEndpoint
{
    /** the bundle's route for the "default" server's HTTP transport */
    public const string ROUTE = '_mcp_endpoint_default';

    private function __construct()
    {
    }
}
