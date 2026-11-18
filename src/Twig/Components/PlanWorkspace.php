<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\SpongeCity\Conditions\SiteConditionsStore;
use App\SpongeCity\Geo\DataSource;
use App\SpongeCity\Placement\AddHere;
use App\SpongeCity\Placement\Placements;
use App\SpongeCity\Placement\Zone;
use App\SpongeCity\Plan\Plan;
use App\SpongeCity\Plan\PlanAnalyzer;
use App\SpongeCity\Plan\PlanObjects;
use App\SpongeCity\Plan\PlanStore;
use App\SpongeCity\Plan\SiteEditor;
use App\SpongeCity\PlanEvaluator;
use Core\Configurator\Product\SpongeCity\Impact\ImpactCalculator;
use Core\Configurator\Product\SpongeCity\SpongeCityParameter;
use Core\Configurator\Scene\SceneSerializer;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\Contracts\Translation\TranslatorInterface;

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
    public const string ACTION_DISMISS_NOTICE = 'dismissNotice';
    /** the live prop the context menu sets to edit an intervention */
    public const string PROP_SELECTED = 'selected';
    /** what an intervention's panel lets a person change: type → field → the values offered */
    public const array EDITABLE = [
        O::RAINGARDEN => [O::STORAGE => [150, 300, 450]],
        O::PERMEABLE => [O::KIND => O::PERMEABLE_KINDS],
        O::NEWTREE => [O::HEIGHT => [6000, 9000, 12000, 15000]],
        O::SHADESAIL => [O::Y => [2500, 3200, 4000]],
    ];
    public const string NOTICE_TEXT = 'text';
    public const string NOTICE_OK = 'ok';
    private const string DOMAIN = 'spongecity';
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
    ) {
    }

    public function plan(): Plan
    {
        return $this->plans->get($this->planId);
    }

    /** the user moved the slider */
    public function onStormUpdated(): void
    {
        $plan = $this->plan();
        $plan->setStorm(max(SpongeCityParameter::STORM_MIN, min(SpongeCityParameter::STORM_MAX, $this->storm)));
        $this->plans->save($plan);
    }

    #[PreReRender]
    public function showThePlansStorm(): void
    {
        $this->storm = $this->plan()->storm();
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
        foreach (['selected', 'equipment'] as $prop) {
            if ($this->{$prop} === $id) {
                $this->{$prop} = null;
            }
        }
        $this->zones = null;
    }

    /**
     * What can be pointed at in the 3D view (PlanObjects), for its context menu.
     *
     * @return list<array<string, mixed>>
     */
    public function pickables(): array
    {
        // normalised: a site as the import wrote it has no ids yet
        return $this->objects->of(Site::fromJson((new SiteType())->normalize($this->plan()->siteJson())), $this->zones());
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
        [$this->zone, $this->equipment, $this->selected] = [null, null, null];
        $id = $result['id'] ?? null;
        $added = str_starts_with($result['outcome'], AddHere::ADDED);
        // what was added opens for editing; a zone that keeps its surface opens to say what it is
        match (true) {
            $added && O::EQUIPMENT === $type => $this->equipment = $id,
            $added => $this->selected = $id,
            in_array($result['outcome'], [AddHere::ZONE_KEPT, AddHere::ZONE_UNKNOWN], true) => $this->zone = $id,
            default => null,
        };
        $params = $result['params'];
        if (isset($params['%kind%'])) {
            $params['%kind%'] = $this->translator->trans('plan.object.kind.'.$params['%kind%'], [], self::DOMAIN);
        }
        if (isset($params['%use%'])) {
            $params['%use%'] = $this->translator->trans('plan.zones.use.'.$params['%use%'], [], self::DOMAIN);
        }
        // a rain garden right where the person pointed needs no "… m from where you clicked"
        $here = (AddHere::ADDED.'.'.O::RAINGARDEN) === $result['outcome'] && 0 === ($params['%distance%'] ?? null);
        $key = $here ? $result['outcome'].'_here' : $result['outcome'];
        $this->notice = [self::NOTICE_TEXT => $this->translator->trans(self::NOTICE_PREFIX.$key, $params, self::DOMAIN), self::NOTICE_OK => $added];
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
            [$this->zone, $this->equipment] = [null, null];
        }
    }

    /**
     * The object selected in the 3D view (an intervention, a tree, a building), with its facts and,
     * for an intervention, what may be changed; null when none is (or it has a panel of its own).
     *
     * @return array{id: string, type: string, name: string, facts: list<array{0: string, 1: string}>, fields: array<string, array{value: mixed, options: list<int|string>}>, removable: bool}|null
     */
    public function selectedObject(): ?array
    {
        if (null === $this->selected) {
            return null;
        }
        // normalised: a site as the import wrote it has no ids yet
        $site = Site::fromJson((new SiteType())->normalize($this->plan()->siteJson()));
        foreach ($site->objects as $object) {
            if ($object[O::ID] !== $this->selected) {
                continue;
            }
            $id = $this->selected;
            // what PlanObjects knows (interventions, trees, buildings); zones and equipment have panels of their own
            $row = array_values(array_filter($this->objects->of(new Site([$object]), []), static fn (array $r): bool => $r['id'] === $id))[0] ?? null;
            if (null === $row || O::EQUIPMENT === $row['type']) {
                return null;
            }
            $fields = [];
            foreach (self::EDITABLE[$object[O::TYPE]] ?? [] as $field => $options) {
                $fields[$field] = ['value' => $object[$field] ?? null, 'options' => $options];
            }

            return [
                'id' => $row['id'],
                'type' => $row['type'],
                'name' => $row['name'],
                'facts' => $row['facts'],
                'fields' => $fields,
                'removable' => in_array(PlanObjects::CAN_DELETE, $row['can'], true),
            ];
        }

        return null;
    }

    /** a field of the intervention open for editing, set to one of the values offered */
    #[LiveAction]
    public function editObject(#[LiveArg] string $id, #[LiveArg] string $field, #[LiveArg] string $value): void
    {
        foreach (Site::fromJson($this->plan()->siteJson())->objects as $object) {
            if ($object[O::ID] !== $id) {
                continue;
            }
            $options = self::EDITABLE[$object[O::TYPE]][$field] ?? [];
            $chosen = array_values(array_filter($options, static fn (int|string $option): bool => (string) $option === $value))[0] ?? null;
            if (null !== $chosen) {
                $this->editor->update($this->planId, $id, [$field => $chosen]);
            }

            return;
        }
    }

    #[LiveAction]
    public function closeSelected(): void
    {
        $this->selected = null;
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
        $site = Site::fromJson($this->plan()->siteJson());
        $plots = $site->ofType(O::PLOT);

        return [
            self::MAP_PLOT => [] === $plots ? [] : Site::points($plots[0]),
            self::MAP_BUILDINGS => array_values(array_filter(array_map(Site::points(...), $site->ofType(O::BUILDING)), static fn (array $points): bool => count($points) >= 3)),
            self::MAP_TREES => array_map(static fn (array $tree): array => ['x' => (float) $tree[O::X], 'z' => (float) $tree[O::Z], 'r' => self::crownRadius($tree)], $site->ofType(O::TREE)),
            self::MAP_INTERVENTIONS => array_values(array_map(fn (array $object): array => [
                'id' => (string) $object[O::ID],
                'type' => $object[O::TYPE],
                'label' => is_string($object[O::LABEL] ?? null) ? $object[O::LABEL] : null,
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
            'label' => is_string($item[O::LABEL] ?? null) ? $item[O::LABEL] : null,
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
            [$this->equipment, $this->selected] = [null, null];
        }
    }

    /** equipment was clicked: the zone's panel gives way */
    public function onEquipmentSelected(): void
    {
        if (null !== $this->equipment) {
            [$this->zone, $this->selected] = [null, null];
        }
    }

    /**
     * The play equipment selected on the map, or null.
     *
     * @return array<string, mixed>|null
     */
    public function selectedEquipment(): ?array
    {
        if (null === $this->equipment) {
            return null;
        }
        foreach (Site::fromJson($this->plan()->siteJson())->ofType(O::EQUIPMENT) as $item) {
            if ($item[O::ID] === $this->equipment) {
                return [...self::equipmentRow($item), 'width' => (int) $item[O::WIDTH], 'depth' => (int) $item[O::DEPTH]];
            }
        }

        return null;
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
        $this->equipment = $this->editor->add($this->planId, [O::equipment(O::EQUIPMENT_OTHER, $x, $z, look: O::LOOK_USER)])['ids'][0] ?? null;
        $this->zone = null;
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
        foreach (Site::fromJson($this->plan()->siteJson())->ofType(O::EQUIPMENT) as $item) {
            if ($item[O::ID] === $id) {
                return $item;
            }
        }

        return null;
    }

    /**
     * The zone selected on the map, or null.
     *
     * @return array<string, mixed>|null
     */
    public function selectedZone(): ?array
    {
        if (null === $this->zone) {
            return null;
        }
        foreach ($this->zones() as $zone) {
            if ($zone->id === $this->zone) {
                return $zone->toArray();
            }
        }

        return null;
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
