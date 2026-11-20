<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\SpongeCity\Conditions\SiteConditionsStore;
use App\SpongeCity\Geo\DataSource;
use App\SpongeCity\Placement\AddHere;
use App\SpongeCity\Placement\GreenRoofSiting;
use App\SpongeCity\Placement\Placements;
use App\SpongeCity\Placement\Zone;
use App\SpongeCity\Plan\Plan;
use App\SpongeCity\Plan\PlanAnalyzer;
use App\SpongeCity\Plan\PlanObjects;
use App\SpongeCity\Plan\PlanStore;
use App\SpongeCity\Plan\SiteEditor;
use App\SpongeCity\PlanEvaluator;
use App\SpongeCity\Rationale\Alternative;
use App\SpongeCity\Rationale\Rationale;
use App\SpongeCity\Rationale\RationaleStore;
use Core\Configurator\Product\SpongeCity\Funding\Applicant;
use Core\Configurator\Product\SpongeCity\GreenRoof\GreenRoofs;
use Core\Configurator\Product\SpongeCity\Impact\ImpactCalculator;
use Core\Configurator\Product\SpongeCity\Impact\Polygon;
use Core\Configurator\Product\SpongeCity\SpongeCityParameter;
use Core\Configurator\Scene\SceneSerializer;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
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
    public const string ACTION_ADD_GREEN_ROOF = 'addGreenRoof';
    public const string ACTION_CLEAR = 'clearInterventions';
    public const string ACTION_RESHAPE_PLOT = 'reshapePlot';
    /** the keys of plotMap() */
    public const string MAP_PLOT = 'plot';
    public const string MAP_BUILDINGS = 'buildings';
    public const string MAP_TREES = 'trees';
    public const string MAP_INTERVENTIONS = 'interventions';
    public const string MAP_ZONES = 'zones';
    public const string MAP_EQUIPMENT = 'equipment';
    public const string ACTION_SET_ZONE_USE = 'setZoneUse';
    public const string ACTION_SET_ZONE_SURFACE = 'setZoneSurface';
    public const string ACTION_CLOSE_ZONE = 'closeZone';
    /** the live prop the map sets when a zone is clicked */
    public const string PROP_ZONE = 'zone';
    /** play equipment: marked on the map with a click, then its kind chosen, turned or removed */
    public const string ACTION_ADD_EQUIPMENT = 'addEquipment';
    public const string ACTION_SET_EQUIPMENT_KIND = 'setEquipmentKind';
    public const string ACTION_TURN_EQUIPMENT = 'turnEquipment';
    public const string ACTION_REMOVE_EQUIPMENT = 'removeEquipment';
    public const string ACTION_CLOSE_EQUIPMENT = 'closeEquipment';
    /** the live prop the map sets when play equipment is clicked */
    public const string PROP_EQUIPMENT = 'equipment';
    /** one press of "turn" */
    public const int EQUIPMENT_TURN_STEP = 45;
    /** the 3D view's context menu: add here, edit, delete (removeObject), details */
    public const string ACTION_ADD_HERE = 'addHere';
    public const string ACTION_EDIT_OBJECT = 'editObject';
    public const string ACTION_CLOSE_SELECTED = 'closeSelected';
    /** a measure picked in the list: selected as if clicked in the view */
    public const string ACTION_SELECT_OBJECT = 'selectObject';
    public const string ACTION_DISMISS_NOTICE = 'dismissNotice';
    /** the live prop the context menu sets to edit an intervention */
    public const string PROP_SELECTED = 'selected';
    /** what an intervention's panel lets a person change: type → field → the values offered */
    public const array EDITABLE = [
        O::RAINGARDEN => [O::STORAGE => [150, 300, 450], O::TRENCH => [0, 500, 1000]],
        O::PERMEABLE => [O::KIND => O::PERMEABLE_KINDS],
        O::NEWTREE => [O::HEIGHT => [6000, 9000, 12000, 15000]],
        O::SHADESAIL => [O::Y => [2500, 3200, 4000]],
        // narrowed per roof to the kinds its building can take (GreenRoofs)
        O::GREENROOF => [O::KIND => O::GREENROOF_KINDS],
    ];
    /** "use this instead" on an alternative the agent had for an object */
    public const string ACTION_USE_ALTERNATIVE = 'useAlternative';
    /** millimetres: a rationale's badge floats this far above its object (above a tree's crown, a sail) */
    public const int MARKER_ABOVE = 1500;
    public const string NOTICE_TEXT = 'text';
    public const string NOTICE_OK = 'ok';
    private const int MILLIMETRES_PER_METRE = 1000;
    private const int SQUARE_MILLIMETRES_PER_SQUARE_METRE = 1_000_000;
    private const string NOTICE_PREFIX = 'plan.add.';
    public const string EVENT_PLAN_CHANGED = 'plan:changed';
    /** where the browser keeps the windows' places and which are open (window_manager_controller.js) */
    public const string WINDOWS_STORAGE_KEY = 'spongecity-windows';
    /** ?debug=1: frame stats overlay, scene and camera on window (bin/render-loop uses them) */
    public const string QUERY_DEBUG = 'debug';
    /** ?tier=0…3: pins a quality tier (debugging) */
    public const string QUERY_TIER = 'tier';
    public const int TIER_UNPINNED = -1;
    /** the last parenthesised word of a publisher: its abbreviation */
    private const string ABBREVIATION = '/\(([^()]+)\)\s*$/';

    #[LiveProp]
    public string $planId = '';

    /** the slider; the plan's storm, which the agent may change too, wins on every re-render */
    #[LiveProp(writable: true, onUpdated: 'onStormUpdated')]
    public int $storm = SpongeCityParameter::STORM_DEFAULT;

    /** who would apply for funding (Applicant value); the plan's, which the agent may set too, wins on every re-render */
    #[LiveProp(writable: true, onUpdated: 'onApplicantUpdated')]
    public string $applicant = '';

    /** @var list<array{role: string, content: string}> */
    #[LiveProp]
    public array $history = [];

    /** @var list<Zone>|null */
    private ?array $zones = null;

    /** the zone selected on the plot map, whose use can be set */
    #[LiveProp(writable: true, onUpdated: 'onZoneSelected')]
    public ?string $zone = null;

    /** the play equipment selected on the plot map */
    #[LiveProp(writable: true, onUpdated: 'onEquipmentSelected')]
    public ?string $equipment = null;

    /** the object selected in the 3D view (an intervention, a tree, a building) */
    #[LiveProp(writable: true, onUpdated: 'onObjectSelected')]
    public ?string $selected = null;

    /** @var array{text: string, ok: bool}|null what the last "add here" did, or why it did not */
    #[LiveProp]
    public ?array $notice = null;

    public function __construct(
        private readonly PlanStore $plans,
        private readonly PlanEvaluator $evaluator,
        private readonly PlanAnalyzer $analyzer,
        private readonly SceneSerializer $serializer,
        private readonly SiteEditor $editor,
        private readonly SiteConditionsStore $conditions,
        private readonly Placements $placements,
        private readonly PlanObjects $objects,
        private readonly AddHere $addHere,
        private readonly TranslatorInterface $translator,
        private readonly RationaleStore $rationales,
    ) {
    }

    public function plan(): Plan
    {
        return $this->plans->get($this->planId);
    }

    private function site(): Site
    {
        return Site::fromJson($this->plan()->siteJson());
    }

    /** the site with an id on every object (a site as the import wrote it has none yet) */
    private function normalizedSite(): Site
    {
        return Site::fromJson((new SiteType())->normalize($this->plan()->siteJson()));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function objectById(string $id, ?string $type = null): ?array
    {
        $objects = null === $type ? $this->site()->objects : $this->site()->ofType($type);

        return array_find($objects, static fn (array $object): bool => $object[O::ID] === $id);
    }

    /**
     * One panel is open at a time: the selected object, zone or equipment ($panel, a prop name);
     * opening one closes the others.
     */
    private function open(string $panel, ?string $id): void
    {
        [$this->selected, $this->zone, $this->equipment] = [null, null, null];
        $this->{$panel} = $id;
    }

    /** the user moved the slider */
    public function onStormUpdated(): void
    {
        $plan = $this->plan();
        $plan->setStorm($this->storm);
        $this->plans->save($plan);
    }

    /** the user chose who applies for funding */
    public function onApplicantUpdated(): void
    {
        $plan = $this->plan();
        $plan->setApplicant(Applicant::tryFrom($this->applicant) ?? Applicant::Unknown);
        $this->plans->save($plan);
    }

    #[PreReRender]
    public function showThePlansSettings(): void
    {
        $plan = $this->plan();
        $this->storm = $plan->storm();
        $this->applicant = $plan->applicant()->value;
    }

    /**
     * @return list<string> the Applicant values, in the order the select lists them
     */
    public function applicants(): array
    {
        return Applicant::values();
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
     * The planned interventions, for the list the user can edit. Each has a `name` (its label,
     * else its kind, numbered when several share it: "Shade tree 2") and a `size` (a tree's
     * height, else its area), so they can be told apart.
     *
     * @return list<array{id: string, type: string, label: string|null, name: string, size: string, cost: float|null}>
     */
    public function interventions(): array
    {
        $rows = [];
        $costs = $this->analyzer->costsByMeasure($this->plan());
        foreach ($this->site()->objects as $object) {
            if (in_array($object[O::TYPE], O::INTERVENTIONS, true)) {
                $label = Site::label($object);
                $rows[] = [
                    'id' => (string) $object[O::ID],
                    'type' => (string) $object[O::TYPE],
                    'label' => $label,
                    'name' => $label ?? $this->translator->trans('plan.measures.type.'.$object[O::TYPE]),
                    'size' => $this->sizeOf($object),
                    'cost' => $costs[(string) $object[O::ID]] ?? null,
                ];
            }
        }
        $shared = array_filter(array_count_values(array_column($rows, 'name')), static fn (int $count): bool => $count > 1);
        $seen = [];
        foreach ($rows as $i => $row) {
            if (isset($shared[$row['name']])) {
                $seen[$row['name']] = ($seen[$row['name']] ?? 0) + 1;
                $rows[$i]['name'] = $this->translator->trans('plan.measures.numbered', ['%name%' => $row['name'], '%number%' => $seen[$row['name']]]);
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $object
     */
    private function sizeOf(array $object): string
    {
        if (O::NEWTREE === $object[O::TYPE]) {
            return $this->translator->trans('plan.measures.size.height', ['%height%' => round((float) ($object[O::HEIGHT] ?? 0) / self::MILLIMETRES_PER_METRE, 1)]);
        }

        return $this->translator->trans('plan.measures.size.area', ['%area%' => (int) round(Polygon::area(Site::points($object)) / self::SQUARE_MILLIMETRES_PER_SQUARE_METRE)]);
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
        $this->rationales->forget($this->planId, $id);
        $this->selected = $this->selected === $id ? null : $this->selected;
        $this->equipment = $this->equipment === $id ? null : $this->equipment;
        $this->zones = null;
    }

    /**
     * What can be pointed at in the 3D view (PlanObjects), for its context menu.
     *
     * @return list<array<string, mixed>>
     */
    public function pickables(): array
    {
        return $this->objects->of($this->normalizedSite(), $this->zones());
    }

    /**
     * Adds a rain garden, permeable surface, shade tree or play equipment where the person pointed
     * in the 3D view (plan millimetres), by the placement rules (AddHere); says what happened.
     */
    #[LiveAction]
    public function addHere(#[LiveArg] string $type, #[LiveArg] int $x, #[LiveArg] int $z): void
    {
        if (!in_array($type, AddHere::TYPES, true)) {
            return;
        }
        $result = $this->addHere->add($this->plan(), $type, $x, $z);
        $this->zones = null;
        $id = $result['id'] ?? null;
        $added = str_starts_with($result['outcome'], AddHere::ADDED);
        // what was added opens for editing; a zone that keeps its surface opens to say what it is
        match (true) {
            $added && O::EQUIPMENT === $type => $this->open(self::PROP_EQUIPMENT, $id),
            $added => $this->open(self::PROP_SELECTED, $id),
            in_array($result['outcome'], [AddHere::ZONE_KEPT, AddHere::ZONE_UNKNOWN], true) => $this->open(self::PROP_ZONE, $id),
            default => $this->open(self::PROP_SELECTED, null),
        };
        $params = $result['params'];
        if (isset($params['%kind%'])) {
            $params['%kind%'] = $this->translator->trans('plan.object.kind.'.$params['%kind%']);
        }
        if (isset($params['%use%'])) {
            $params['%use%'] = $this->translator->trans('plan.zones.use.'.$params['%use%']);
        }
        // a rain garden right where the person pointed needs no "… m from where you clicked"
        $here = (AddHere::ADDED.'.'.O::RAINGARDEN) === $result['outcome'] && 0 === ($params['%distance%'] ?? null);
        $key = $here ? $result['outcome'].'_here' : $result['outcome'];
        $this->notice = [self::NOTICE_TEXT => $this->translator->trans(self::NOTICE_PREFIX.$key, $params), self::NOTICE_OK => $added];
    }

    /** a green roof on the building the person pointed at in the 3D view, by the placement rules */
    #[LiveAction]
    public function addGreenRoof(#[LiveArg] string $id): void
    {
        $result = $this->addHere->greenRoof($this->plan(), $id);
        $added = str_starts_with($result['outcome'], AddHere::ADDED);
        $this->open(self::PROP_SELECTED, $added ? ($result['id'] ?? null) : null);
        $this->notice = [self::NOTICE_TEXT => $this->translator->trans(self::NOTICE_PREFIX.$result['outcome'], $result['params']), self::NOTICE_OK => $added];
    }

    #[LiveAction]
    public function dismissNotice(): void
    {
        $this->notice = null;
    }

    /** an object selected in the 3D view: the other panels give way */
    public function onObjectSelected(): void
    {
        if (null !== $this->selected) {
            $this->open(self::PROP_SELECTED, $this->selected);
        }
    }

    /**
     * The object selected in the 3D view (an intervention, a tree, a building), with its facts and,
     * for an intervention, what may be changed; null when none is (or it has a panel of its own).
     *
     * @return array{id: string, type: string, name: string, facts: list<array{0: string, 1: string, 2: string|null}>, fields: array<string, array{value: mixed, options: list<int|string>}>, removable: bool}|null
     */
    public function selectedObject(): ?array
    {
        $id = $this->selected;
        $object = null === $id ? null : array_find($this->normalizedSite()->objects, static fn (array $object): bool => $object[O::ID] === $id);
        if (null === $object) {
            return null;
        }
        // what PlanObjects knows (interventions, trees, buildings); zones and equipment have panels of their own
        // a green roof is read with its building and the plot
        $context = O::GREENROOF === $object[O::TYPE] ? $this->normalizedSite() : new Site([$object]);
        $row = array_find($this->objects->of($context, []), static fn (array $row): bool => $row['id'] === $id);
        if (null === $row || O::EQUIPMENT === $row['type']) {
            return null;
        }
        $fields = [];
        foreach (self::EDITABLE[$object[O::TYPE]] ?? [] as $field => $options) {
            $fields[$field] = ['value' => $object[$field] ?? null, 'options' => $this->optionsFor($object, $field, $options)];
        }

        return [
            'id' => $row['id'],
            'type' => $row['type'],
            'name' => $row['name'],
            // a fact that may be changed shows as the choice made below, not twice
            'facts' => array_values(array_filter($row['facts'], static fn (array $fact): bool => !isset($fields[$fact[2] ?? '']))),
            'fields' => $fields,
            'removable' => in_array(PlanObjects::CAN_DELETE, $row['can'], true),
            'rationale' => $this->rationale($row['id']),
        ];
    }

    /**
     * Where the badges of the objects the agent explained float in the 3D view: over the middle of
     * each, MARKER_ABOVE over its top (plan millimetres).
     *
     * @return list<array{id: string, x: float, z: float, lift: float, by: string, title: string}>
     */
    public function markers(): array
    {
        $rationales = $this->rationales->of($this->planId);
        $markers = [];
        foreach ($this->pickables() as $object) {
            $rationale = $rationales->for($object['id']);
            if (null === $rationale) {
                continue;
            }
            [$x, $z] = isset($object['points'])
                ? [array_sum(array_column($object['points'], 0)) / count($object['points']), array_sum(array_column($object['points'], 1)) / count($object['points'])]
                : [$object['x'], $object['z']];
            $markers[] = [
                'id' => $object['id'],
                'x' => round($x),
                'z' => round($z),
                'lift' => (float) ($object['top'] ?? 0) + self::MARKER_ABOVE,
                'by' => $rationale->by,
                'title' => $this->translator->trans('plan.rationale.badge', ['%name%' => $object['name']]),
            ];
        }

        return $markers;
    }

    /**
     * Why an object is in the plan, ready to read: the agent's words, the reasons and warnings, and
     * the alternatives with a summary and their outline (to preview them in the view).
     *
     * @return array{why: string|null, by: string, reasons: list<string>, warnings: list<string>, alternatives: list<array{id: string, summary: string, points: list<array{0: int|float, 1: int|float}>, agent: bool}>}|null
     */
    private function rationale(string $id): ?array
    {
        $rationale = $this->rationales->of($this->planId)->for($id);
        if (null === $rationale) {
            return null;
        }

        return [
            'why' => $rationale->why,
            'by' => $rationale->by,
            'reasons' => $rationale->reasons,
            'warnings' => $rationale->warnings,
            'alternatives' => array_map(fn (Alternative $alternative): array => [
                'id' => $alternative->id,
                'summary' => $this->summary($alternative),
                'points' => Site::points($alternative->object),
                'agent' => $alternative->id === $rationale->agentCandidate,
            ], $rationale->alternatives),
        ];
    }

    /** an alternative in a line: its kind, shape and size, and what it does for the water */
    private function summary(Alternative $alternative): string
    {
        $metrics = $alternative->metrics;
        $parameters = [
            '%area%' => (int) round((float) ($metrics['area_m2'] ?? 0)),
            '%planted%' => (int) round((float) ($metrics[GreenRoofSiting::METRIC_PLANTED] ?? 0)),
            '%catchment%' => (int) round((float) ($metrics['catchment_m2'] ?? 0), -1),
            '%retained%' => number_format((float) ($metrics['retained_m3'] ?? 0), 1),
            '%removed%' => number_format((float) ($metrics['runoff_removed_m3'] ?? 0), 1),
            '%kind%' => isset($metrics['kind']) ? $this->translator->trans('plan.object.kind.'.$metrics['kind']) : '',
            '%shape%' => isset($metrics['shape']) ? $this->translator->trans('plan.rationale.shape.'.$metrics['shape']) : '',
        ];

        return $this->translator->trans('plan.rationale.summary.'.($alternative->object[O::TYPE] ?? ''), $parameters);
    }

    /**
     * A person takes one of the alternatives the agent had for an object instead (or the agent's
     * choice back): the object is replaced by it, and its rationale says whose choice it is now.
     */
    #[LiveAction]
    public function useAlternative(#[LiveArg] string $id, #[LiveArg] string $alternative): void
    {
        $rationale = $this->rationales->of($this->planId)->for($id);
        $taken = $rationale?->alternative($alternative);
        $current = $this->objectById($id);
        if (null === $rationale || null === $taken || null === $current) {
            return;
        }
        $this->editor->remove($this->planId, $id);
        $added = $this->editor->add($this->planId, [$taken->object])['ids'][0] ?? null;
        unset($current[O::ID]);
        if (null === $added) {
            // it no longer fits (something was built there since): the object stays as it was
            $restored = $this->editor->add($this->planId, [$current])['ids'][0] ?? null;
            if (null !== $restored) {
                $this->rationales->forget($this->planId, $id);
                $this->rationales->record($this->planId, new Rationale($restored, $rationale->why, $rationale->reasons, $rationale->warnings, $rationale->metrics, $rationale->alternatives, $rationale->by, $rationale->candidate, $rationale->agentCandidate));
                $this->selected = $restored;
            }
            $this->notice = [self::NOTICE_TEXT => $this->translator->trans('plan.rationale.no_longer_fits'), self::NOTICE_OK => false];

            return;
        }
        $this->rationales->forget($this->planId, $id);
        $this->rationales->record($this->planId, $rationale->swappedFor($taken, $added, $current));
        $this->selected = $added;
        $this->zones = null;
    }

    /** a field of the intervention open for editing, set to one of the values offered */
    #[LiveAction]
    public function editObject(#[LiveArg] string $id, #[LiveArg] string $field, #[LiveArg] string $value): void
    {
        $object = $this->objectById($id);
        $options = null === $object ? [] : $this->optionsFor($object, $field, self::EDITABLE[$object[O::TYPE]][$field] ?? []);
        $chosen = array_find($options, static fn (int|string $option): bool => (string) $option === $value);
        if (null !== $chosen) {
            $this->editor->update($this->planId, $id, [$field => $chosen]);
        }
    }

    /**
     * The values a person may choose for a field of this object: a green roof's kinds are only those
     * its building can take (a roof garden is not offered on a hall).
     *
     * @param array<string, mixed> $object
     * @param list<int|string>     $options
     *
     * @return list<int|string>
     */
    private function optionsFor(array $object, string $field, array $options): array
    {
        if (O::GREENROOF !== $object[O::TYPE] || O::KIND !== $field) {
            return $options;
        }
        $assessment = GreenRoofs::assessIn($this->normalizedSite(), (string) ($object[O::BUILDING_ID] ?? ''));

        return array_values(array_filter($options, static fn (int|string $kind): bool => null !== $assessment && $assessment->allows((string) $kind)));
    }

    #[LiveAction]
    public function closeSelected(): void
    {
        $this->selected = null;
    }

    #[LiveAction]
    public function selectObject(#[LiveArg] string $id): void
    {
        $this->open(self::PROP_SELECTED, $id);
    }

    #[LiveAction]
    public function clearInterventions(): void
    {
        $this->editor->clearInterventions($this->planId);
    }

    /**
     * The plot drawn anew on the plot map.
     *
     * @param list<mixed> $points [x, z] in plan millimetres
     */
    #[LiveAction]
    public function reshapePlot(#[LiveArg] array $points = []): void
    {
        $this->editor->reshapePlot($this->planId, $points);
    }

    /**
     * What the plot map draws (plan millimetres, x east, z south): the plot's outline, the
     * buildings' footprints and the existing trees' crowns around it, and the planned
     * interventions: an outline each, or a new tree's position and crown.
     *
     * @return array{plot: list<array{float, float}>, buildings: list<list<array{float, float}>>, trees: list<array{x: float, z: float, r: float}>, interventions: list<array{id: string, type: string, label: string|null, points?: list<array{float, float}>, x?: float, z?: float, r?: float}>}
     */
    public function plotMap(): array
    {
        $site = $this->site();

        return [
            self::MAP_PLOT => $site->plotOutline(),
            self::MAP_BUILDINGS => array_values(array_filter(array_map(Site::points(...), $site->ofType(O::BUILDING)), static fn (array $points): bool => count($points) >= 3)),
            self::MAP_TREES => array_map(static fn (array $tree): array => ['x' => (float) $tree[O::X], 'z' => (float) $tree[O::Z], 'r' => self::crownRadius($tree)], $site->ofType(O::TREE)),
            self::MAP_INTERVENTIONS => array_values(array_map(fn (array $object): array => [
                'id' => (string) $object[O::ID],
                'type' => $object[O::TYPE],
                'label' => Site::label($object),
                ...(O::NEWTREE === $object[O::TYPE]
                    ? ['x' => (float) $object[O::X], 'z' => (float) $object[O::Z], 'r' => self::crownRadius($object)]
                    : ['points' => Site::points($object)]),
            ], array_filter($site->objects, static fn (array $object): bool => in_array($object[O::TYPE], O::INTERVENTIONS, true)))),
            self::MAP_ZONES => array_map(static fn (Zone $zone): array => $zone->toArray(), $this->zones()),
            self::MAP_EQUIPMENT => array_map(static fn (array $item): array => self::equipmentRow($item), $site->ofType(O::EQUIPMENT)),
        ];
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array{id: string, kind: string, label: string|null, x: float, z: float, points: list<array{float, float}>, look: string}
     */
    private static function equipmentRow(array $item): array
    {
        return [
            'id' => (string) $item[O::ID],
            'kind' => (string) $item[O::KIND],
            'label' => Site::label($item),
            'x' => (float) $item[O::X],
            'z' => (float) $item[O::Z],
            // the fall zone
            'points' => array_map(static fn (array $p): array => [round($p[0]), round($p[1])], Site::outlinesOf($item)[0]),
            'look' => (string) ($item[O::LOOK] ?? O::LOOK_DATA),
        ];
    }

    /** a zone was clicked: the equipment's panel gives way */
    public function onZoneSelected(): void
    {
        if (null !== $this->zone) {
            $this->open(self::PROP_ZONE, $this->zone);
        }
    }

    /** equipment was clicked: the zone's panel gives way */
    public function onEquipmentSelected(): void
    {
        if (null !== $this->equipment) {
            $this->open(self::PROP_EQUIPMENT, $this->equipment);
        }
    }

    /**
     * The play equipment selected on the map, or null.
     *
     * @return array<string, mixed>|null
     */
    public function selectedEquipment(): ?array
    {
        $item = null === $this->equipment ? null : $this->equipmentById($this->equipment);

        return null === $item ? null : [...self::equipmentRow($item), 'width' => (int) $item[O::WIDTH], 'depth' => (int) $item[O::DEPTH]];
    }

    /**
     * @return list<string>
     */
    public function equipmentKinds(): array
    {
        return O::EQUIPMENT_KINDS;
    }

    /** the person marked play equipment on the map (plan millimetres): it is selected, to say what it is */
    #[LiveAction]
    public function addEquipment(#[LiveArg] int $x, #[LiveArg] int $z): void
    {
        $this->open(self::PROP_EQUIPMENT, $this->editor->add($this->planId, [O::equipment(O::EQUIPMENT_OTHER, $x, $z, look: O::LOOK_USER)])['ids'][0] ?? null);
        $this->zones = null;
    }

    /** what the selected equipment is: its fall zone becomes that kind's */
    #[LiveAction]
    public function setEquipmentKind(#[LiveArg] string $id, #[LiveArg] string $kind): void
    {
        $item = $this->equipmentById($id);
        if (null === $item || !in_array($kind, O::EQUIPMENT_KINDS, true)) {
            return;
        }
        $sized = O::equipment($kind, (int) $item[O::X], (int) $item[O::Z], (int) $item[O::TURN], O::LOOK_USER);
        $this->editor->update($this->planId, $id, array_intersect_key($sized, array_flip([O::KIND, O::WIDTH, O::DEPTH, O::HEIGHT, O::LOOK])));
        $this->zones = null;
    }

    /** the selected equipment turned by a step: a swing swings one way */
    #[LiveAction]
    public function turnEquipment(#[LiveArg] string $id): void
    {
        $item = $this->equipmentById($id);
        if (null === $item) {
            return;
        }
        // -180…180 as the field has it
        $turn = ((int) $item[O::TURN] + self::EQUIPMENT_TURN_STEP + 180) % 360 - 180;
        $this->editor->update($this->planId, $id, [O::TURN => $turn, O::LOOK => O::LOOK_USER]);
        $this->zones = null;
    }

    #[LiveAction]
    public function removeEquipment(#[LiveArg] string $id): void
    {
        if (null !== $this->equipmentById($id)) {
            $this->editor->remove($this->planId, $id);
        }
        $this->equipment = null;
        $this->zones = null;
    }

    #[LiveAction]
    public function closeEquipment(): void
    {
        $this->equipment = null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function equipmentById(string $id): ?array
    {
        return $this->objectById($id, O::EQUIPMENT);
    }

    /**
     * The zone selected on the map, or null.
     *
     * @return array<string, mixed>|null
     */
    public function selectedZone(): ?array
    {
        $selected = $this->zone;

        return array_find($this->zones(), static fn (Zone $zone): bool => $zone->id === $selected)?->toArray();
    }

    /**
     * The plot's zones, read once per request (the map and the selection both need them).
     *
     * @return list<Zone>
     */
    private function zones(): array
    {
        return $this->zones ??= $this->placements->zones($this->plan());
    }

    /**
     * @return list<string>
     */
    public function zoneUses(): array
    {
        return O::AREA_USES;
    }

    /** the person says what the selected zone is used for */
    #[LiveAction]
    public function setZoneUse(#[LiveArg] string $zone, #[LiveArg] string $use): void
    {
        $this->zone = $this->placements->describeZone($this->plan(), $zone, use: $use) ?? $this->zone;
        $this->zones = null;
    }

    /** the person says what the selected zone's ground is today */
    #[LiveAction]
    public function setZoneSurface(#[LiveArg] string $zone, #[LiveArg] string $surface): void
    {
        $this->zone = $this->placements->describeZone($this->plan(), $zone, surface: $surface) ?? $this->zone;
        $this->zones = null;
    }

    /**
     * @return list<string>
     */
    public function zoneSurfaces(): array
    {
        return O::SURFACES;
    }

    #[LiveAction]
    public function closeZone(): void
    {
        $this->zone = null;
    }

    /**
     * @param array<string, mixed> $tree
     *
     * @return float millimetres: the recorded crown, else the mature crown the impact calculation assumes
     */
    private static function crownRadius(array $tree): float
    {
        $diameter = is_numeric($tree[O::DIAMETER] ?? null) ? (float) $tree[O::DIAMETER] : 0.0;

        return round($diameter > 0 ? $diameter / 2 : (is_numeric($tree[O::HEIGHT] ?? null) ? (float) $tree[O::HEIGHT] : 0.0) * ImpactCalculator::CANOPY_RADIUS_SHARE);
    }

    public function minPlotCorners(): int
    {
        return SiteEditor::minPlotCorners();
    }

    public function maxPlotCorners(): int
    {
        return O::MAX_POINTS;
    }
}
