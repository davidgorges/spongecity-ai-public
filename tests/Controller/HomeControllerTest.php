<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ExampleSchool;
use App\Controller\PlaceSearch;
use App\SpongeCity\Geo\NominatimGeocoder;
use App\SpongeCity\Import\ImportSite;
use App\SpongeCity\Plan\PlanStatus;
use App\SpongeCity\Plan\PlanStore;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
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

        $client->request('POST', '/', [PlaceSearch::FIELD => ' Rathausmarkt ']);

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

        $client->request('POST', '/', [PlaceSearch::FIELD => 'Nowhere']);

        self::assertResponseRedirects('/');
        $client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'could not be found');
        self::assertCount(0, static::getContainer()->get('messenger.transport.async')->getSent());
    }

    #[Test]
    public function eachExampleSchoolStartsItsPlanInOneClick(): void
    {
        foreach (ExampleSchool::cases() as $school) {
            $asked = null;
            $client = static::createClient();
            $client->disableReboot();
            static::getContainer()->set(NominatimGeocoder::class, new NominatimGeocoder(new MockHttpClient(
                static function (string $method, string $url) use (&$asked, $school): MockResponse {
                    parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
                    $asked = $query['q'] ?? null;

                    return new JsonMockResponse([['lat' => '53.57', 'lon' => '10.02', 'display_name' => $school->value.', Hamburg']]);
                },
            ), new NullLogger()));

            $crawler = $client->request('GET', '/');
            $button = $crawler->filter('#schools-title + ul button')->reduce(
                static fn ($node) => str_contains($node->text(), $school->value) && str_contains($node->text(), $school->district()),
            );
            self::assertCount(1, $button, sprintf('The home page should offer %s.', $school->value));

            $client->submit($button->form());

            self::assertSame($school->place(), $asked);
            self::assertResponseRedirects();
            $id = basename((string) $client->getResponse()->headers->get('Location'));
            self::assertSame($school->value.', Hamburg', static::getContainer()->get(PlanStore::class)->get($id)->title);
            self::ensureKernelShutdown();
        }
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
