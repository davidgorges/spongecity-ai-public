<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\SpongeCity\Geo\NominatimGeocoder;
use App\SpongeCity\Import\ImportSite;
use App\SpongeCity\Plan\PlanStatus;
use App\SpongeCity\Plan\PlanStore;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The home page's form: a place found starts a plan and queues its import; a place not found says so.
 */
final class HomeControllerTest extends WebTestCase
{
    #[Test]
    public function aPlaceFoundStartsAPlanAndQueuesItsImport(): void
    {
        $client = $this->clientFinding([['lat' => '53.55', 'lon' => '10.0', 'display_name' => 'Rathausmarkt, Hamburg']]);

        $client->request('POST', '/', ['place' => ' Rathausmarkt ']);

        self::assertResponseRedirects();
        $id = basename((string) $client->getResponse()->headers->get('Location'));
        $plan = static::getContainer()->get(PlanStore::class)->get($id);
        self::assertSame('Rathausmarkt, Hamburg', $plan->title);
        self::assertSame(PlanStatus::Pending, $plan->status);
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertInstanceOf(ImportSite::class, $transport->getSent()[0]->getMessage());
    }

    #[Test]
    public function aPlaceNotFoundGoesBackHomeWithAnError(): void
    {
        $client = $this->clientFinding([]);

        $client->request('POST', '/', ['place' => 'Nowhere']);

        self::assertResponseRedirects('/');
        $client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'could not be found');
        self::assertCount(0, static::getContainer()->get('messenger.transport.async')->getSent());
    }

    /**
     * @param list<array<string, string>> $hits what Nominatim answers
     */
    private function clientFinding(array $hits): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set(NominatimGeocoder::class, new NominatimGeocoder(new MockHttpClient(new JsonMockResponse($hits)), new NullLogger()));

        return $client;
    }
}
