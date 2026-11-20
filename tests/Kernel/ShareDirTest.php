<?php

namespace App\Tests\Kernel;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ShareDirTest extends KernelTestCase
{
    public function testBootCreatesTheShareDirAndTheDatabaseLivesInIt(): void
    {
        $shareDir = self::bootKernel()->getShareDir();
        self::ensureKernelShutdown();
        (new Filesystem())->remove($shareDir);

        $kernel = self::bootKernel();

        self::assertDirectoryExists($shareDir);

        /** @var Connection $connection */
        $connection = $kernel->getContainer()->get('doctrine.dbal.default_connection');
        self::assertSame(1, (int) $connection->fetchOne('SELECT 1'));
        self::assertStringStartsWith($shareDir.'/', $connection->getParams()['path']);
        self::assertFileExists($connection->getParams()['path']);
    }
}
