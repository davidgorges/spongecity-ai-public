<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * /mcp is for outside clients on this machine (Claude Desktop, the inspector): the in-app agent
 * starts the server over stdio. Any other host is refused (DNS rebinding protection).
 */
final class McpAllowedHostsTest extends WebTestCase
{
    private const string INITIALIZE = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"test","version":"1"}}}';

    #[Test]
    public function onlyLocalhostIsAnswered(): void
    {
        $client = static::createClient();

        self::assertSame(Response::HTTP_OK, $this->initialize($client, 'localhost'));
        self::assertSame(Response::HTTP_FORBIDDEN, $this->initialize($client, 'spongecity.example.org'));
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
