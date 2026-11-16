<?php

declare(strict_types=1);

namespace App\Tests\SpongeCity\Geo;

use App\SpongeCity\Geo\GeoPoint;
use App\SpongeCity\Geo\OverpassClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OverpassClientTest extends TestCase
{
    #[Test]
    public function asksForTheAreasThePointLiesIn(): void
    {
        $query = '';
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$query): MockResponse {
            parse_str(is_string($options['body'] ?? null) ? $options['body'] : '', $form);
            $query = is_string($form['data'] ?? null) ? $form['data'] : '';

            return new MockResponse('{"elements": [{"type": "way", "id": 1}]}');
        });

        $elements = (new OverpassClient($client, new ArrayAdapter(), 'test'))->around(new GeoPoint(53.5513, 10.0188, 'Test'), 80);

        self::assertSame([['type' => 'way', 'id' => 1]], $elements);
        // "around:1" would only find areas whose outline passes within a metre of the point
        self::assertStringContainsString('is_in(53.551300,10.018800)->.containing', $query);
        self::assertStringContainsString('way(pivot.containing)["amenity"', $query);
        self::assertStringNotContainsString('around:1,', $query);
    }
}
