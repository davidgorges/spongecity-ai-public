<?php

declare(strict_types=1);

namespace App\Tests\Configurator;

use Core\Configurator\Product\SpongeCity\InterventionBuilder;
use Core\Configurator\Scene\Environment;
use Core\Configurator\Site\BuildingType;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SuburbanSiteTest extends TestCase
{
    #[Test]
    public function lowHousesMakeASuburb(): void
    {
        $site = $this->site([[6500, BuildingType::House], [7000, BuildingType::House], [9000, BuildingType::School]]);

        self::assertTrue(InterventionBuilder::isSuburban($site));
        self::assertTrue((new InterventionBuilder())->build($site)->environmentHints()[Environment::SUBURBAN]);
    }

    #[Test]
    public function tallBlocksAreTheCity(): void
    {
        self::assertFalse(InterventionBuilder::isSuburban($this->site([[18000, BuildingType::Office], [21000, BuildingType::Apartments], [12000, BuildingType::School]])));
    }

    #[Test]
    public function aSiteWithoutBuildingsIsNotASuburb(): void
    {
        self::assertFalse(InterventionBuilder::isSuburban($this->site([])));
    }

    /**
     * @param list<array{int, BuildingType}> $buildings height in millimetres and use
     */
    private function site(array $buildings): Site
    {
        $objects = array_map(static fn (array $b, int $i): array => [
            O::TYPE => O::BUILDING, O::HEIGHT => $b[0], O::USE => $b[1]->value,
            O::POINTS => [[$i * 20000, 0], [$i * 20000 + 10000, 0], [$i * 20000 + 10000, 8000], [$i * 20000, 8000]],
        ], $buildings, array_keys($buildings));

        return Site::fromJson((new SiteType())->normalize(['objects' => $objects]));
    }
}
