<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\SpongeCity\Conditions\SiteConditionsStore;
use App\SpongeCity\Geo\DataSource;
use App\SpongeCity\Plan\Plan;
use App\SpongeCity\Plan\PlanAnalyzer;
use App\SpongeCity\Plan\PlanStore;
use App\SpongeCity\Plan\SiteEditor;
use App\SpongeCity\PlanEvaluator;
use Core\Configurator\Product\SpongeCity\SpongeCityParameter;
use Core\Configurator\Scene\SceneSerializer;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The planning workspace: the 3D scene of the plan, its measurable balance and the
 * planned interventions. The agent changes the plan through MCP tools in other
 * requests; the page re-renders this component when the chat reports a change.
 */
#[AsLiveComponent('PlanWorkspace')]
final class PlanWorkspace
{
    use DefaultActionTrait;

    public const string ACTION_REMOVE = 'removeObject';
    public const string ACTION_CLEAR = 'clearInterventions';
    public const string EVENT_PLAN_CHANGED = 'plan:changed';
    /** ?debug=1: frame stats overlay, scene and camera on window (bin/render-loop uses them) */
    public const string QUERY_DEBUG = 'debug';
    /** ?tier=0…3: pins a quality tier (debugging) */
    public const string QUERY_TIER = 'tier';
    public const int TIER_UNPINNED = -1;
    /** the last parenthesised word of a publisher: its abbreviation */
    private const string ABBREVIATION = '/\(([^()]+)\)\s*$/';

    #[LiveProp]
    public string $planId = '';

    #[LiveProp(writable: true)]
    public int $storm = SpongeCityParameter::STORM_DEFAULT;

    /** @var list<array{role: string, content: string}> */
    #[LiveProp]
    public array $history = [];

    public function __construct(
        private readonly PlanStore $plans,
        private readonly PlanEvaluator $evaluator,
        private readonly PlanAnalyzer $analyzer,
        private readonly SceneSerializer $serializer,
        private readonly SiteEditor $editor,
        private readonly SiteConditionsStore $conditions,
    ) {
    }

    public function plan(): Plan
    {
        $plan = $this->plans->get($this->planId);
        $storm = max(SpongeCityParameter::STORM_MIN, min(SpongeCityParameter::STORM_MAX, $this->storm));
        if ($storm !== $plan->storm()) {
            $plan->setStorm($storm);
            $this->plans->save($plan);
        }

        return $plan;
    }

    /**
     * @return array<string, mixed> the scene JSON for the viewer
     */
    public function scene(): array
    {
        $evaluation = $this->evaluator->evaluate($this->plan()->values);

        return $this->serializer->serialize($evaluation->scene(), $evaluation->definition->materials(), $evaluation->setting());
    }

    /**
     * @return array<string, mixed>
     */
    public function impact(): array
    {
        return $this->analyzer->impact($this->plan())->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function cost(): array
    {
        return $this->analyzer->cost($this->plan());
    }

    /**
     * The planned interventions, for the list the user can edit.
     *
     * @return list<array{id: string, type: string, label: string|null}>
     */
    public function interventions(): array
    {
        $rows = [];
        foreach (Site::fromJson($this->plan()->siteJson())->objects as $object) {
            if (in_array($object[O::TYPE], O::INTERVENTIONS, true)) {
                $rows[] = ['id' => (string) $object[O::ID], 'type' => (string) $object[O::TYPE], 'label' => is_string($object[O::LABEL] ?? null) ? $object[O::LABEL] : null];
            }
        }

        return $rows;
    }

    /**
     * The official sources of the site's layers, for the attribution under the view.
     *
     * @return list<array<string, string>>
     */
    public function sources(): array
    {
        return array_map(static fn (DataSource $source): array => $source->toArray(), $this->conditions->get($this->planId)->sources);
    }

    /**
     * The offices behind the sources, by their abbreviation where they give one ("… (LGV)" → "LGV"), each once.
     *
     * @return list<string>
     */
    public function publishers(): array
    {
        $offices = array_map(static function (array $source): string {
            $publisher = $source[DataSource::PUBLISHER];

            return 1 === preg_match(self::ABBREVIATION, $publisher, $match) ? $match[1] : $publisher;
        }, $this->sources());

        return array_values(array_unique($offices));
    }

    public function minStorm(): int
    {
        return SpongeCityParameter::STORM_MIN;
    }

    public function maxStorm(): int
    {
        return SpongeCityParameter::STORM_MAX;
    }

    #[LiveAction]
    public function removeObject(#[LiveArg] string $id): void
    {
        $this->editor->remove($this->planId, $id);
    }

    #[LiveAction]
    public function clearInterventions(): void
    {
        $this->editor->clearInterventions($this->planId);
    }
}
