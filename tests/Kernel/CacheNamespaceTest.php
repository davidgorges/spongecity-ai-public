<?php

namespace App\Tests\Kernel;

use App\Kernel;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Plans live in the app cache pool. With zero-downtime deploys every release runs from its own
 * directory, so a pool namespace seeded from the path would hide plans between releases, and
 * between the web process and workers still running the previous release.
 */
final class CacheNamespaceTest extends KernelTestCase
{
    private string $releaseDir;

    protected function setUp(): void
    {
        $this->releaseDir = sys_get_temp_dir().'/spongecity-release-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem())->remove($this->releaseDir);
    }

    public function testAnotherReleaseDirectoryReadsTheSameAppPool(): void
    {
        $kernel = self::bootKernel();
        $pool = $kernel->getContainer()->get('cache.app');
        \assert($pool instanceof CacheItemPoolInterface);
        $key = 'release_test_'.bin2hex(random_bytes(4));
        $pool->save($pool->getItem($key)->set('stored by the first release'));
        $shareDir = $kernel->getShareDir();
        self::ensureKernelShutdown();

        (new Filesystem())->symlink($kernel->getProjectDir(), $this->releaseDir);
        $nextRelease = new class('test', false, $this->releaseDir, $shareDir) extends Kernel {
            public function __construct(string $env, bool $debug, private string $dir, private string $sharedDir)
            {
                parent::__construct($env, $debug);
            }

            public function getProjectDir(): string
            {
                return $this->dir;
            }

            public function getCacheDir(): string
            {
                return sys_get_temp_dir().'/'.basename($this->dir).'-cache';
            }

            public function getShareDir(): string
            {
                return $this->sharedDir;
            }
        };
        $nextRelease->boot();

        try {
            $item = $nextRelease->getContainer()->get('cache.app')->getItem($key);
            self::assertTrue($item->isHit(), 'A new release directory must see the app pool of the previous one.');
            self::assertSame('stored by the first release', $item->get());
        } finally {
            $nextRelease->shutdown();
            (new Filesystem())->remove($nextRelease->getCacheDir());
        }
    }
}
