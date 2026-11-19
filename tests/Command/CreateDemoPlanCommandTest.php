<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\SpongeCity\Plan\PlanStore;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CreateDemoPlanCommandTest extends KernelTestCase
{
    #[Test]
    public function itPrintsAClickableLinkToTheNewPlan(): void
    {
        self::bootKernel();
        $tester = new CommandTester((new Application(self::$kernel))->find('app:plan:demo'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = preg_replace('/\s+/', '', $tester->getDisplay());
        self::assertMatchesRegularExpression('#https?://[^/]+/plan/([0-9A-Z]{26})#', $display);

        preg_match('#/plan/([0-9A-Z]{26})#', $display, $match);
        self::assertNotNull(static::getContainer()->get(PlanStore::class)->find($match[1]));
    }
}
