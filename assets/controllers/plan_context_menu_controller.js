import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';
import { contains, edgeDistance } from '../configurator/outline_geometry.js';

/** the element of the live component this menu belongs to */
const LIVE_ROOT = '[data-controller~="live"]';
/** how objects are found (PlanObjects::ON_GROUND / UPRIGHT), and a zone's type */
const UPRIGHT = 'upright';
const ZONE = 'zone';
const BUILDING = 'building';
/** what the menu offers (PlanObjects::CAN_*) */
const CAN_DELETE = 'delete';
/** a building whose roof can be planted (PlanObjects::CAN_GREENROOF) */
const CAN_GREENROOF = 'greenroof';
const GREENROOF = 'greenroof';
/** which panel edits which kind of object (the props value's keys) */
const EQUIPMENT = 'equipment';
/** a point this high over the ground (metres) is on something standing: a wall, a crown, a sail */
const UPRIGHT_ABOVE = 0.4;
/** a click on a wall lands on the footprint's edge, a click at a tree's foot near its trunk (millimetres) */
const WALL_TOLERANCE = 400;
const TRUNK_REACH = 800;
/** a model that was hit is the tree standing within this of it (millimetres) */
const MODEL_MATCH = 500;
/** standing on the ground: what lies on top wins (equipment over a rain garden over its zone) */
const GROUND_ORDER = ['equipment', 'raingarden', 'planting', 'permeable', 'newtree', 'tree', ZONE];
/** above it: what stands highest in front */
const UPRIGHT_ORDER = ['shadesail', 'newtree', 'tree', GREENROOF, BUILDING, 'equipment', 'raingarden', 'planting', 'permeable', ZONE];
/** keeps the menu this far (CSS pixels) inside the view */
const EDGE_MARGIN = 8;
const KEY_NEXT = 'ArrowDown';
const KEY_PREVIOUS = 'ArrowUp';
const KEY_CLOSE = 'Escape';
const KEY_TAB = 'Tab';
/** dispatched as plan-context-menu:opened / :closed: the view holds its hover tooltip meanwhile */
const EVENT_OPENED = 'opened';
const EVENT_CLOSED = 'closed';
/** dispatched as plan-context-menu:highlight, detail {object} (PlanObjects row, or null): the view marks it */
const EVENT_HIGHLIGHT = 'highlight';
/** dispatched as plan-context-menu:hovered, detail {object, selected, marker, clientX, clientY}: the view marks it faintly */
const EVENT_HOVERED = 'hovered';
/** an armed delete: the item template styles itself destructive by it */
const VARIANT_DESTRUCTIVE = 'destructive';

/**
 * What is clicked in the 3D view, and its context menu. Both events (from configurator-viewer)
 * say where the pointer met the scene; the object there is found among the plan's (PlanObjects:
 * outlines in plan millimetres).
 *
 * configurator:select (a click) makes it the workspace's selection: highlighted, its facts and
 * what may be changed in the details window. configurator:context (a right-click or the context
 * menu key) opens the menu of what can be done there:
 *
 * - delete (a second click confirms),
 * - add here: a rain garden, permeable surface, shade tree or play equipment, placed by the
 *   server's rules near the point (PlanWorkspace::addHere), which says what it did.
 *
 * The menu is a role="menu" of buttons: arrow keys move, Escape closes and gives the view
 * back its focus, a click elsewhere closes it. Its parts are copies of server-rendered templates
 * (the Toolkit's DropdownMenu: a label, an item, a separator, a group label), their text put into
 * the element marked data-text.
 *
 * configurator:hover (the mouse over the view) is answered with the object there (EVENT_HOVERED),
 * which the view marks faintly and names in a tooltip.
 *
 * While it is open the view marks the object it is about; once it closes, the one whose side
 * panel is open (the `highlighted` value: the workspace's selected intervention, equipment or zone).
 */
export default class extends Controller {
    static targets = ['menu', 'label', 'item', 'separator', 'group'];
    static values = {
        objects: Array,
        plot: Array,
        actions: Object,
        props: Object,
        addable: Array,
        texts: Object,
        /** the id of the object whose panel is open, '' for none */
        highlighted: String,
    };

    /** where the menu was opened: plan millimetres and the object found there */
    #at = null;
    /** the view had the focus: it gets it back */
    #returnFocus = null;

    /**
     * A click on the view (configurator:select): the object there becomes the workspace's
     * selection, its panel opens in the details window; a click on nothing selects nothing.
     */
    async select({ detail }) {
        this.close();
        const target = detail ? this.#objectAt(detail) : null;
        const component = await getComponent(this.element.closest(LIVE_ROOT));
        if (!target) {
            if (this.highlightedValue !== '') {
                // the three panels, closed with one re-render
                component.set(this.propsValue.zone, null, false);
                component.set(this.propsValue.equipment, null, false);
                component.set(this.propsValue.intervention, null, true);
            }

            return;
        }
        if (target.id === this.highlightedValue) {
            return;
        }
        component.set(this.#propFor(target), target.id, true);
    }

    /** The mouse over the view (configurator:hover): which object it is over, for the view to mark. */
    hover({ detail }) {
        if (!this.menuTarget.hidden) {
            return;
        }
        const object = detail ? this.#objectAt(detail) : null;
        this.dispatch(EVENT_HOVERED, {
            detail: {
                object,
                selected: object !== null && object.id === this.highlightedValue,
                marker: detail?.marker ?? null,
                clientX: detail?.clientX ?? 0,
                clientY: detail?.clientY ?? 0,
            },
        });
    }

    open({ detail }) {
        if (!detail) {
            return;
        }
        const target = this.#objectAt(detail);
        // on a building nothing is added: its wall is no ground (its roof may be planted)
        const onPlot = target?.type !== BUILDING && contains(this.plotValue, detail.x, detail.z);
        if (!onPlot && !target?.can.includes(CAN_DELETE) && !target?.can.includes(CAN_GREENROOF)) {
            // nothing to do here: no menu
            return;
        }
        this.#returnFocus = document.activeElement;
        this.#at = { x: detail.x, z: detail.z, object: target };
        this.#render(target, onPlot);
        this.menuTarget.hidden = false;
        this.dispatch(EVENT_OPENED);
        this.#highlight(target);
        this.#place(detail.clientX, detail.clientY);
        this.#items()[0]?.focus({ preventScroll: true });
    }

    close() {
        if (this.menuTarget.hidden) {
            return;
        }
        this.menuTarget.hidden = true;
        this.menuTarget.replaceChildren();
        this.#at = null;
        this.dispatch(EVENT_CLOSED);
        this.#highlightPanel();
    }

    /** a panel opened or closed (the workspace re-rendered) */
    highlightedValueChanged() {
        if (this.menuTarget.hidden) {
            this.#highlightPanel();
        }
    }

    /** the plan changed (an object edited, added, removed): its mark follows it */
    objectsValueChanged() {
        if (this.menuTarget.hidden) {
            this.#highlightPanel();
        }
    }

    #highlightPanel() {
        this.#highlight(this.objectsValue.find((object) => object.id === this.highlightedValue) ?? null);
    }

    #highlight(object) {
        this.dispatch(EVENT_HIGHLIGHT, { detail: { object } });
    }

    /** a press anywhere else closes it */
    outside(event) {
        if (!this.menuTarget.hidden && !this.menuTarget.contains(event.target)) {
            this.close();
        }
    }

    keydown(event) {
        if (this.menuTarget.hidden) {
            return;
        }
        if (event.key === KEY_CLOSE || event.key === KEY_TAB) {
            event.preventDefault();
            this.close();
            this.#returnFocus?.focus({ preventScroll: true });

            return;
        }
        if (event.key !== KEY_NEXT && event.key !== KEY_PREVIOUS) {
            return;
        }
        event.preventDefault();
        const items = this.#items();
        const index = items.indexOf(document.activeElement);
        const step = event.key === KEY_NEXT ? 1 : -1;
        items[(index + step + items.length) % items.length]?.focus({ preventScroll: true });
    }

    /** The object under the point: the tree whose model was hit, else among those it lies in the one on top. */
    #objectAt({ x, z, above, model, marker }) {
        // a badge names its object
        const explained = marker ? this.objectsValue.find((object) => object.id === marker) : null;
        if (explained) {
            return explained;
        }
        // a crown is drawn wider than its estimate: the model's trunk says which tree it is
        const tree = model ? this.objectsValue.find((object) => object.r !== undefined && Math.hypot(object.x - model.x, object.z - model.z) < MODEL_MATCH) : null;
        if (tree) {
            return tree;
        }
        const upright = above > UPRIGHT_ABOVE;
        const order = upright ? UPRIGHT_ORDER : GROUND_ORDER;
        const hits = this.objectsValue.filter((object) => this.#covers(object, x, z, upright));
        hits.sort((a, b) => this.#rank(order, a) - this.#rank(order, b));

        return hits[0] ?? null;
    }

    #rank(order, object) {
        const index = order.indexOf(object.type);

        return index < 0 ? order.length : index;
    }

    #covers(object, x, z, upright) {
        if (object.points) {
            return contains(object.points, x, z) || (object.type === BUILDING && edgeDistance(object.points, x, z) < WALL_TOLERANCE);
        }
        // a tree: its crown when the point is up in it, its trunk when on the ground
        const distance = Math.hypot(object.x - x, object.z - z);

        return upright ? distance <= object.r : distance <= TRUNK_REACH;
    }

    #render(object, onPlot) {
        const texts = this.textsValue;
        const name = object?.name ?? texts.nothing;
        this.menuTarget.setAttribute('aria-label', texts.label.replace('%name%', name));
        const children = [this.#part(this.labelTarget, name)];

        if (object?.can.includes(CAN_DELETE)) {
            let armed = false;
            const remove = this.#item(texts.delete, 'delete', () => {
                if (!armed) {
                    // the second click deletes: nothing is lost by a slip
                    armed = true;
                    remove.querySelector('[data-text]').textContent = texts.confirmDelete;
                    remove.dataset.variant = VARIANT_DESTRUCTIVE;

                    return;
                }
                this.#act(this.actionsValue.remove, { id: object.id });
            });
            children.push(remove);
        }
        if (object?.can.includes(CAN_GREENROOF)) {
            children.push(this.#item(texts.greenRoof, CAN_GREENROOF, () => this.#act(this.actionsValue.greenRoof, { id: object.id })));
        }
        if (onPlot) {
            children.push(this.#part(this.separatorTarget), this.#part(this.groupTarget, texts.addHere));
            this.addableValue.forEach((type) => {
                children.push(this.#item(texts.add[type] ?? type, `add-${type}`, () => this.#act(this.actionsValue.add, { type, x: this.#at.x, z: this.#at.z })));
            });
        }
        this.menuTarget.replaceChildren(...children);
    }

    #item(text, key, run) {
        const button = this.#part(this.itemTarget, text);
        button.dataset.item = key;
        button.addEventListener('click', run);

        return button;
    }

    #part(template, text = null) {
        const node = template.content.firstElementChild.cloneNode(true);
        if (text !== null) {
            (node.querySelector('[data-text]') ?? node).textContent = text;
        }

        return node;
    }

    /** which of the workspace's props selects it: zones and play equipment have panels of their own */
    #propFor(object) {
        if (object.type === ZONE) {
            return this.propsValue.zone;
        }

        return object.type === EQUIPMENT ? this.propsValue.equipment : this.propsValue.intervention;
    }

    async #act(action, args) {
        this.close();
        const component = await getComponent(this.element.closest(LIVE_ROOT));
        await component.action(action, args);
    }

    #items() {
        return [...this.menuTarget.querySelectorAll('[role="menuitem"]')];
    }

    /** next to the pointer, inside the view */
    #place(clientX, clientY) {
        const frame = this.menuTarget.offsetParent?.getBoundingClientRect() ?? { left: 0, top: 0 };
        this.menuTarget.style.left = `${clientX - frame.left}px`;
        this.menuTarget.style.top = `${clientY - frame.top}px`;
        this.#keepInside();
    }

    #keepInside() {
        const parent = this.menuTarget.offsetParent;
        if (!parent) {
            return;
        }
        const frame = parent.getBoundingClientRect();
        const menu = this.menuTarget.getBoundingClientRect();
        const left = Math.min(menu.left - frame.left, frame.width - menu.width - EDGE_MARGIN);
        const top = Math.min(menu.top - frame.top, frame.height - menu.height - EDGE_MARGIN);
        this.menuTarget.style.left = `${Math.max(EDGE_MARGIN, left)}px`;
        this.menuTarget.style.top = `${Math.max(EDGE_MARGIN, top)}px`;
    }
}
