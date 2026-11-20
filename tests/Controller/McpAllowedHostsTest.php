<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The in-app agent calls the MCP server by MCP_SERVER_URL; in production that is the public host,
 * which the server must answer. Any other host is refused (DNS rebinding protection): without its
 * tools, the agent would write its tool calls as text.
 */
final class McpAllowedHostsTest extends WebTestCase
{
    private const string ENV = 'MCP_SERVER_URL';
    private const string PRODUCTION_HOST = 'spongecity.example.org';
    private const string INITIALIZE = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"test","version":"1"}}}';

    private mixed $previous = null;

    protected function setUp(): void
    {
        $this->previous = $_SERVER[self::ENV] ?? null;
        $_SERVER[self::ENV] = $_ENV[self::ENV] = sprintf('https://%s/mcp', self::PRODUCTION_HOST);
    }

    protected function tearDown(): void
    {
        $_SERVER[self::ENV] = $_ENV[self::ENV] = $this->previous;
        parent::tearDown();
    }

    #[Test]
    public function theHostOfTheServerUrlIsAnsweredAndOthersAreNot(): void
    {
        $client = static::createClient();

        self::assertSame(Response::HTTP_OK, $this->initialize($client, self::PRODUCTION_HOST));
        self::assertSame(Response::HTTP_OK, $this->initialize($client, 'localhost'));
        self::assertSame(Response::HTTP_FORBIDDEN, $this->initialize($client, 'evil.example.com'));
    }

    private function initialize(KernelBrowser $client, string $host): int
    {
        $client->request('POST', '/mcp', server: [
            'HTTP_HOST' => $host,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ], content: self::INITIALIZE);

        return $client->getResponse()->getStatusCode();
    }
}
