<?php

declare(strict_types=1);

namespace App\SpongeCity\Placement;

use Core\Configurator\Product\SpongeCity\GreenRoof\GreenRoofAssessment;
use Core\Configurator\Product\SpongeCity\GreenRoof\GreenRoofs;
use Core\Configurator\Product\SpongeCity\Impact\CoolingCalculator;
use Core\Configurator\Product\SpongeCity\Impact\GroundClass;
use Core\Configurator\Product\SpongeCity\Impact\Polygon;
use Core\Configurator\Product\SpongeCity\SpongeCityDefinition;
use Core\Configurator\Product\SpongeCity\SpongeCitySku;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Which of the plot's buildings can take a green roof (GreenRoofs: flat roofs the 3D model
 * measured, large enough, no roof garden on halls), largest planted area first, each with what
 * it holds back, how much cooler its roof gets and what it costs. The buildings that cannot
 * take one are named in the note, with the reason.
 */
#[AsTaggedItem(index: O::GREENROOF)]
final readonly class GreenRoofSiting implements InterventionSiting
{
    public const string METRIC_BUILDING = 'building';
    public const string METRIC_ROOF = 'roof_m2';
    public const string METRIC_PLANTED = 'planted_m2';
    public const string METRIC_KIND = 'kind';
    public const string METRIC_INTENSIVE = 'intensive_possible';
    public const string METRIC_HALL = 'hall';
    public const string METRIC_RETAINED = 'retained_m3_design_storm';
    public const string METRIC_YEARLY = 'yearly_runoff_avoided_m3';
    public const string METRIC_ROOF_COOLING = 'roof_cooler_k';
    public const string METRIC_COST = 'cost_eur';
    public const string METRIC_COST_PER_M2 = 'cost_eur_per_m2_planted';
    private const float MILLIMETRES_PER_METRE = 1000.0;
    private const float CENTS_PER_EURO = 100.0;
    /** a candidate near the point the user named comes first */
    private const float NEAR_MM = 30000.0;

    public function __construct(
        private SpongeCityDefinition $definition,
    ) {
    }

    public function propose(PlacementContext $context, PlacementRequest $request): Proposal
    {
        $site = new Site($context->objects);
        $plot = $site->plot();
        $outline = null === $plot ? [] : Site::points($plot);
        $candidates = [];
        $ineligible = [];
        foreach ($site->ofType(O::BUILDING) as $building) {
            $assessment = GreenRoofs::assess($building, $outline);
            if (!$assessment->isEligible()) {
                if ($assessment->onPlot) {
                    $ineligible[] = sprintf('%s (%s): %s', $assessment->label ?? $assessment->buildingId, $assessment->buildingId, $assessment->reasons[0] ?? '');
                }
                continue;
            }
            if (null !== GreenRoofs::onBuilding($site, $assessment->buildingId)) {
                $ineligible[] = sprintf('%s (%s): already has a green roof.', $assessment->label ?? $assessment->buildingId, $assessment->buildingId);
                continue;
            }
            $candidates[] = $this->candidate($building, $assessment, $context->stormMillimetres);
        }

        usort($candidates, static function (Candidate $a, Candidate $b) use ($request, $site): int {
            if (null !== $request->near) {
                $near = static fn (Candidate $c): bool => self::distance($site, $c, $request->near) <= self::NEAR_MM;
                if ($near($a) !== $near($b)) {
                    return $near($b) <=> $near($a);
                }
            }

            return $b->metrics[self::METRIC_PLANTED] <=> $a->metrics[self::METRIC_PLANTED];
        });
        $ranked = [];
        foreach (array_slice($candidates, 0, $request->count) as $i => $candidate) {
            $ranked[] = $candidate->withId(sprintf('%s-%d', O::GREENROOF, $i + 1));
        }

        $note = null;
        if ([] === $ranked) {
            $note = 'No roof on the plot can take a green roof.';
        }
        if ([] !== $ineligible) {
            $note = trim(($note ?? '').' Not offered: '.implode(' ', $ineligible));
        }

        return new Proposal(O::GREENROOF, $ranked, [], $this->assumptions(), $note);
    }

    public function stillFits(PlacementContext $context, Candidate $candidate): ?string
    {
        $site = new Site($context->objects);
        $buildingId = (string) ($candidate->object[O::BUILDING_ID] ?? '');
        $assessment = GreenRoofs::assessIn($site, $buildingId);
        if (null === $assessment || !$assessment->isEligible()) {
            return 'The building can no longer take a green roof: propose again.';
        }

        return null === GreenRoofs::onBuilding($site, $buildingId) ? null : 'This building already has a green roof.';
    }

    /**
     * @param array<string, mixed> $building
     */
    private function candidate(array $building, GreenRoofAssessment $assessment, int $storm): Candidate
    {
        $kind = O::KIND_EXTENSIVE;
        $class = GroundClass::greenRoof($kind);
        $gross = $assessment->grossSquareMetres();
        $planted = $assessment->plantedSquareMetres();
        $rain = $storm / self::MILLIMETRES_PER_METRE;
        $year = CoolingCalculator::DEFAULT_ANNUAL_PRECIPITATION_MM / self::MILLIMETRES_PER_METRE;
        $bare = GroundClass::Roof;
        $cost = ($gross * $this->price(SpongeCitySku::GREENROOF_EXTENSIVE) + $this->price(SpongeCitySku::GREENROOF_SETUP)) / self::CENTS_PER_EURO;

        return new Candidate(
            O::GREENROOF,
            [O::TYPE => O::GREENROOF, O::BUILDING_ID => $assessment->buildingId, O::KIND => $kind, O::POINTS => Site::points($building)],
            [
                self::METRIC_BUILDING => $assessment->buildingId,
                self::METRIC_ROOF => round($gross, 1),
                self::METRIC_PLANTED => round($planted, 1),
                self::METRIC_KIND => $kind,
                self::METRIC_INTENSIVE => $assessment->allows(O::KIND_INTENSIVE),
                self::METRIC_HALL => $assessment->isHall,
                self::METRIC_RETAINED => round($planted * $rain * ($bare->runoffCoefficient() - $class->runoffCoefficient()), 1),
                self::METRIC_YEARLY => round($planted * $year * ($bare->yearlyWaterBalance()[GroundClass::RUNOFF] - $class->yearlyWaterBalance()[GroundClass::RUNOFF]), 1),
                self::METRIC_ROOF_COOLING => round($bare->surfaceTemperature() - $class->surfaceTemperature(), 1),
                self::METRIC_COST => round($cost, -2),
                self::METRIC_COST_PER_M2 => $planted > 0 ? round($cost / $planted) : null,
            ],
            $assessment->reasons,
            $assessment->warnings,
        );
    }

    private function price(string $sku): float
    {
        return (float) ($this->definition->defaultPrices()->priceOf($sku) ?? 0);
    }

    /**
     * @param array{0: int|float, 1: int|float} $near
     */
    private static function distance(Site $site, Candidate $candidate, array $near): float
    {
        $points = $candidate->object[O::POINTS];

        return Polygon::contains($points, (float) $near[0], (float) $near[1]) ? 0.0 : (float) hypot(
            array_sum(array_column($points, 0)) / count($points) - $near[0],
            array_sum(array_column($points, 1)) / count($points) - $near[1],
        );
    }

    /**
     * @return list<string>
     */
    private function assumptions(): array
    {
        return [
            'Only the plot\'s own buildings with a flat roof measured in the 3D building model; roof levels of at least 25 m² and 2 m wide, planted inside a 0.5 m gravel strip (FLL Dachbegrünungsrichtlinien).',
            'Candidates are extensive (sedum, 100 to 150 kg/m²). intensive_possible marks roofs large and solid-looking enough for a roof garden (300 kg/m² and more): change the kind with update_object after placing, and say that it needs a structural engineer and usually reinforcement.',
            'Retained in the design storm: planted area x (0.9 - 0.5) x rain depth (DIN 1986-100); runoff avoided per year: planted area x 790 mm x (80 % - 45 %) (DWA-M 102-4). Roof surface on a summer midday: 55 °C bare, 36 °C extensive.',
            sprintf('Cost: retrofit price %d €/m² of roof (extensive; intensive %d €/m²) plus %d € per building for the structural survey, crane and scaffolding. Not included: a new waterproofing, reinforcement, railings.', (int) ($this->price(SpongeCitySku::GREENROOF_EXTENSIVE) / self::CENTS_PER_EURO), (int) ($this->price(SpongeCitySku::GREENROOF_INTENSIVE) / self::CENTS_PER_EURO), (int) ($this->price(SpongeCitySku::GREENROOF_SETUP) / self::CENTS_PER_EURO)),
            'Roofs suited to a green roof often suit PV too: a solar green roof combines both (panels raised over the sedum). Weigh it with simulate_buildings pv_potential when the user asks about energy.',
        ];
    }
}
