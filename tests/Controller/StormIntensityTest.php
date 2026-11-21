<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\SpongeCity\Plan\PlanStore;
use App\Twig\Components\PlanWorkspace;
use Core\Configurator\Product\SpongeCity\StormIntensity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Next to the simulated rainfall, a word for how heavy it is: few people know what 40 mm/h means.
 */
final class StormIntensityTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    /** @return iterable<array{int, StormIntensity}> */
    public static function levels(): iterable
    {
        yield [10, StormIntensity::Downpour];
        yield [14, StormIntensity::Downpour];
        yield [15, StormIntensity::Heavy];
        yield [25, StormIntensity::Severe];
        yield [40, StormIntensity::Cloudburst];
        yield [79, StormIntensity::Cloudburst];
        yield [80, StormIntensity::Extreme];
        yield [120, StormIntensity::Extreme];
    }

    #[Test]
    #[DataProvider('levels')]
    public function aRainfallHasTheLevelTheWeatherServiceWouldWarnOf(int $millimetresPerHour, StormIntensity $level): void
    {
        self::assertSame($level, StormIntensity::of($millimetresPerHour));
    }

    #[Test]
    public function theEffectWindowNamesHowHeavyTheRainIsAndFollowsTheSlider(): void
    {
        $plan = static::getContainer()->get(PlanStore::class)->create('Schulhof', 53.5513, 10.0188);
        $component = $this->createLiveComponent(PlanWorkspace::class, ['planId' => $plan->id]);

        $window = $component->render()->crawler()->filter('[data-window="effect"]');
        self::assertStringContainsString('Simulated rainfall', $window->text());
        $component->set('storm', 40);
        $intensity = $component->render()->crawler()->filter('[data-window="effect"] [data-storm-intensity]');
        self::assertSame('cloudburst', $intensity->attr('data-storm-intensity'));
        self::assertStringContainsString('Cloudburst', $intensity->text());

        $component->set('storm', 20);
        $intensity = $component->render()->crawler()->filter('[data-window="effect"] [data-storm-intensity]');
        self::assertSame('heavy', $intensity->attr('data-storm-intensity'));
        self::assertStringContainsString('Heavy rain', $intensity->text());
    }
}
