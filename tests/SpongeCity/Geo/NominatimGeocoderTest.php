<?php

declare(strict_types=1);

namespace App\Tests\SpongeCity\Geo;

use App\SpongeCity\Geo\NominatimGeocoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The same address is asked once; what was not found, or failed, is asked again.
 */
final class NominatimGeocoderTest extends TestCase
{
    #[Test]
    public function theSameAddressIsAskedOnlyOnce(): void
    {
        $http = new MockHttpClient([new JsonMockResponse([['lat' => '53.5866', 'lon' => '10.0101', 'display_name' => 'Gelehrtenschule des Johanneums, Hamburg']])]);
        $geocoder = new NominatimGeocoder($http, new NullLogger(), new ArrayAdapter());

        $first = $geocoder->search('Gelehrtenschule des Johanneums, Hamburg');
        $again = $geocoder->search('  gelehrtenschule des johanneums, hamburg ');

        self::assertSame(1, $http->getRequestsCount());
        self::assertEquals($first, $again);
        self::assertSame('Gelehrtenschule des Johanneums, Hamburg', $again[0]->label);
        self::assertSame(53.5866, $again[0]->latitude);
    }

    #[Test]
    public function aPlaceNotFoundOrAFailureIsAskedAgain(): void
    {
        $http = new MockHttpClient([
            new JsonMockResponse([]),
            new MockResponse('', ['http_code' => 503]),
            new JsonMockResponse([['lat' => '53.55', 'lon' => '10.0', 'display_name' => 'Somewhere, Hamburg']]),
        ]);
        $geocoder = new NominatimGeocoder($http, new NullLogger(), new ArrayAdapter());

        self::assertSame([], $geocoder->search('Somewhere'));
        self::assertSame([], $geocoder->search('Somewhere'));
        self::assertCount(1, $geocoder->search('Somewhere'));
        self::assertSame(3, $http->getRequestsCount());
    }
}
