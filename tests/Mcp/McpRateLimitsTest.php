<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Mcp\SpongeCityTools;
use App\SpongeCity\Geo\NominatimGeocoder;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

/**
 * /mcp is open, so a client that sends too much is turned away: every request beyond its share
 * with 429, an expensive tool beyond its share with a tool error the agent can read.
 * (config/packages/rate_limiter.yaml: 10 requests and 1 expensive call in the test environment).
 */
final class McpRateLimitsTest extends WebTestCase
{
    use ForgetsRateLimits;

    #[Test]
    public function aClientSendingTooManyRequestsIsTurnedAway(): void
    {
        $browser = $this->browser();
        $mcp = new McpClient($browser);

        for ($request = 1; $request <= 10; ++$request) {
            $mcp->send('ping', null);
            self::assertNotSame(429, $browser->getResponse()->getStatusCode(), sprintf('request %d is within the share', $request));
        }
        $mcp->send('ping', null);

        self::assertResponseStatusCodeSame(429);
        self::assertResponseHasHeader('Retry-After');
    }

    #[Test]
    public function anExpensiveToolIsRefusedBeyondItsShare(): void
    {
        $browser = $this->browser();
        $mcp = new McpClient($browser);
        $mcp->initialize();

        $first = $mcp->call(SpongeCityTools::TOOL_SEARCH_PLACE, ['query' => 'Brecht-Schule, Hamburg']);
        $second = $mcp->call(SpongeCityTools::TOOL_SEARCH_PLACE, ['query' => 'Johanneum, Hamburg']);

        self::assertFalse($first['result']['isError'] ?? false, json_encode($first, \JSON_THROW_ON_ERROR));
        self::assertTrue($second['result']['isError'] ?? false, json_encode($second, \JSON_THROW_ON_ERROR));
        self::assertStringContainsString('used too often', $second['result']['content'][0]['text']);
    }

    private function browser(): KernelBrowser
    {
        $browser = static::createClient();
        $browser->disableReboot();
        self::forgetRateLimits($browser);
        $browser->getContainer()->set(NominatimGeocoder::class, new NominatimGeocoder(new MockHttpClient(static fn (): JsonMockResponse => new JsonMockResponse([['lat' => '53.5564', 'lon' => '10.0145', 'display_name' => 'Brecht-Schule, Hamburg']])), new NullLogger(), new ArrayAdapter()));

        return $browser;
    }
}
