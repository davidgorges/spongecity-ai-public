<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Mcp\SpongeCityTools;
use App\SpongeCity\Plan\PlanStore;
use Core\Configurator\Product\SpongeCity\Funding\Applicant;
use Core\Configurator\Product\SpongeCity\Funding\HamburgFunding;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use Mcp\Capability\Attribute\Schema;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Funding as the agent gets it: Hamburg's programmes for a plan in Hamburg, by who applies; none elsewhere.
 */
final class FundingToolsTest extends KernelTestCase
{
    #[Test]
    public function estimatesHamburgsFundingByWhoApplies(): void
    {
        $tools = static::getContainer()->get(SpongeCityTools::class);
        $planId = $this->planWithPlanting(53.5513, 10.0188);

        $upTo = $tools->calculateImpact($planId)['cost'];
        self::assertTrue($upTo['funding']['up_to']);
        $programme = $upTo['funding']['programmes'][0];
        self::assertSame(HamburgFunding::UNSEALING, $programme['key']);
        self::assertEqualsWithDelta($programme['eligible_cost'] * $programme['share'], $upTo['funding']['total'], 0.01);
        self::assertEqualsWithDelta($upTo['total'] - $upTo['funding']['total'], $upTo['net_total'], 0.01);
        self::assertNotEmpty($programme['conditions']);

        self::assertSame(Applicant::Public->value, $tools->setFundingApplicant($planId, Applicant::Public->value));
        $public = $tools->calculateImpact($planId)['cost'];
        self::assertFalse($public['funding']['up_to']);
        self::assertSame(0.0, (float) $public['funding']['total']);
        self::assertSame(Applicant::Unknown->value, $tools->setFundingApplicant($planId, 'nobody'));
    }

    #[Test]
    public function knowsNoProgrammeOutsideHamburg(): void
    {
        $tools = static::getContainer()->get(SpongeCityTools::class);

        $cost = $tools->calculateImpact($this->planWithPlanting(48.1374, 11.5755))['cost'];

        self::assertSame([], $cost['funding']['programmes']);
        self::assertSame($cost['total'], $cost['net_total']);
    }

    #[Test]
    public function theToolOffersEveryApplicant(): void
    {
        $parameter = new \ReflectionParameter([SpongeCityTools::class, 'setFundingApplicant'], 'applicant');
        $schema = $parameter->getAttributes(Schema::class)[0]->newInstance();

        self::assertSame(Applicant::values(), $schema->enum);
    }

    private function planWithPlanting(float $latitude, float $longitude): string
    {
        $plans = static::getContainer()->get(PlanStore::class);
        $plan = $plans->create('Schulhof', $latitude, $longitude);
        $plan->replaceSite((new SiteType())->normalize(['objects' => [
            [O::TYPE => O::PLOT, O::POINTS => [[-20000, -20000], [20000, -20000], [20000, 20000], [-20000, 20000]], O::SURFACE => O::SURFACE_ASPHALT],
            [O::TYPE => O::PLANTING, O::POINTS => [[0, 0], [10000, 0], [10000, 5000], [0, 5000]]],
        ]]));
        $plans->save($plan);

        return $plan->id;
    }
}
