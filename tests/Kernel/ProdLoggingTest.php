<?php

namespace App\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

/**
 * In prod, errors must reach a file under var/log: PHP-FPM drops the stderr of web requests,
 * and Forge shares var/log across releases.
 */
final class ProdLoggingTest extends TestCase
{
    public function testAnErrorInProdIsWrittenToTheLogDir(): void
    {
        $projectDir = \dirname(__DIR__, 2);
        $command = 'no-such-command-'.bin2hex(random_bytes(4));

        $process = new Process([\PHP_BINARY, 'bin/console', $command], $projectDir, ['APP_ENV' => 'prod', 'APP_DEBUG' => '0']);
        $process->run();
        self::assertFalse($process->isSuccessful());

        $files = (new Finder())->files()->in($projectDir.'/var/log')->name('prod-*.log')->contains($command);
        self::assertCount(1, $files, 'The error should be in a prod log file under var/log.');
    }
}
