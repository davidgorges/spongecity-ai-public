<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Talks to /mcp the way an agent a person connects does (streamable HTTP, JSON answers): the
 * handshake once, then tool calls in its session.
 */
final class McpClient
{
    private const array HEADERS = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'];
    private const string PROTOCOL_VERSION = '2025-06-18';

    private ?string $session = null;
    private int $id = 0;

    public function __construct(private readonly KernelBrowser $browser, private readonly string $path = '/mcp')
    {
    }

    public function initialize(): void
    {
        $this->send('initialize', ['protocolVersion' => self::PROTOCOL_VERSION, 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test-agent', 'version' => '1']]);
        $this->session = $this->browser->getResponse()->headers->get('Mcp-Session-Id');
        $this->send('notifications/initialized', null, notification: true);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed> the JSON-RPC answer
     */
    public function call(string $tool, array $arguments): array
    {
        return $this->send('tools/call', ['name' => $tool, 'arguments' => $arguments]);
    }

    /**
     * @param array<string, mixed>|null $params
     *
     * @return array<string, mixed>
     */
    public function send(string $method, ?array $params, bool $notification = false): array
    {
        $message = ['jsonrpc' => '2.0', 'method' => $method] + (null === $params ? [] : ['params' => $params]) + ($notification ? [] : ['id' => ++$this->id]);
        $headers = self::HEADERS + (null === $this->session ? [] : ['HTTP_MCP_SESSION_ID' => $this->session, 'HTTP_MCP_PROTOCOL_VERSION' => self::PROTOCOL_VERSION]);
        $this->browser->request('POST', $this->path, [], [], $headers, json_encode($message, \JSON_THROW_ON_ERROR));

        $content = (string) $this->browser->getResponse()->getContent();

        return '' === $content ? [] : json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
    }
}
