<?php

declare(strict_types=1);

namespace App\Command;

use App\SpongeCity\Geo\GeoPoint;
use App\SpongeCity\Geo\SiteImporter;
use App\SpongeCity\Plan\PlanStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A synthetic schoolyard (a sealed yard between a school building and a gym), made from
 * OpenStreetMap-shaped data: for trying the planner without the network.
 */
#[AsCommand(name: 'app:plan:demo', description: 'Creates a plan with a synthetic, fully sealed schoolyard')]
final class CreateDemoPlanCommand extends Command
{
    private const float LATITUDE = 51.3397;
    private const float LONGITUDE = 12.3731;
    private const float METRES_PER_DEGREE_LATITUDE = 111_320.0;

    public function __construct(
        private readonly SiteImporter $importer,
        private readonly PlanStore $plans,
    ) {
        parent::__construct();
    }

    protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $centre = new GeoPoint(self::LATITUDE, self::LONGITUDE, 'Demo schoolyard');

        $import = $this->importer->import($centre, [
            ['type' => 'way', 'tags' => ['amenity' => 'school', 'name' => 'Demo schoolyard'], 'geometry' => $this->rectangle(-24, -14, 24, 22)],
            ['type' => 'way', 'tags' => ['building' => 'school', 'building:levels' => '3'], 'geometry' => $this->rectangle(-24, -34, 24, -14)],
            ['type' => 'way', 'tags' => ['building' => 'sports_hall', 'height' => '8'], 'geometry' => $this->rectangle(28, -10, 44, 12)],
            ['type' => 'way', 'tags' => ['building' => 'residential', 'building:levels' => '5'], 'geometry' => $this->rectangle(-24, 26, 24, 40)],
            ['type' => 'node', 'lat' => $this->lat(18), 'lon' => $this->lon(20), 'tags' => ['natural' => 'tree']],
            ['type' => 'node', 'lat' => $this->lat(-6), 'lon' => $this->lon(-22), 'tags' => ['natural' => 'tree']],
        ]);

        $plan = $this->plans->create($centre->label, $centre->latitude, $centre->longitude);
        $plan->replaceSite($import->siteJson);
        $this->plans->save($plan);
        $io->success(sprintf('Plan %s: /plan/%s', $plan->id, $plan->id));

        return Command::SUCCESS;
    }

    private function lat(float $southMetres): float
    {
        return self::LATITUDE - $southMetres / self::METRES_PER_DEGREE_LATITUDE;
    }

    private function lon(float $eastMetres): float
    {
        return self::LONGITUDE + $eastMetres / (self::METRES_PER_DEGREE_LATITUDE * cos(deg2rad(self::LATITUDE)));
    }

    /**
     * @return list<array{lat: float, lon: float}>
     */
    private function rectangle(float $west, float $north, float $east, float $south): array
    {
        $corners = [[$west, $north], [$east, $north], [$east, $south], [$west, $south], [$west, $north]];

        return array_map(fn (array $c): array => ['lat' => $this->lat($c[1]), 'lon' => $this->lon($c[0])], $corners);
    }
}
