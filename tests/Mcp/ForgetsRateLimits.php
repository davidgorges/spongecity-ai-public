<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Each test that talks to /mcp starts with its client's full share (config/packages/rate_limiter.yaml,
 * when@test): what an earlier test spent is forgotten.
 */
trait ForgetsRateLimits
{
    /** the test environment's limiter storage */
    private const string RATE_LIMITS_POOL = 'rate_limiter.test';

    private static function forgetRateLimits(KernelBrowser $browser): void
    {
        $pool = $browser->getContainer()->get(self::RATE_LIMITS_POOL);
        \assert($pool instanceof CacheItemPoolInterface);
        $pool->clear();
    }
}
