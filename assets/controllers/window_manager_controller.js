import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';
import { press } from '../configurator/press.js';
import { hangsUnder, snapMove, snapResize, toGrid, touching } from '../window_manager/snap.js';
import { anchor, chainBelow, join, leave, members, normalise, split } from '../window_manager/groups.js';

/** the element of the live component the windows belong to (it re-renders them) */
const LIVE_ROOT = '[data-controller~="live"]';
/** windows float from this width on (Tailwind's lg); below, one is shown at a time under the view */
const FLOATING = '(min-width: 1024px)';
/** CSS pixels: room kept around windows, under the view's title bar, above the dock */
const MARGIN = 16;
const TOP = 76;
const BOTTOM = 72;
const GAP = 12;
/** a window opened below others needs this much room above the dock, else it opens over them (CSS pixels) */
const MIN_ROOM = 240;
/** windows opened over others step down this far, so each title bar shows */
const CASCADE = 36;
/** a window is never squeezed below this height, nor resized narrower than this (CSS pixels) */
const MIN_HEIGHT = 120;
const MIN_WIDTH = 280;
/** an arrow key on a window's grip moves it this far (Shift: further) */
const NUDGE = 16;
const BIG_NUDGE = 64;
const MOVES = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };
/** a press on these in a title bar is theirs, not a drag */
const CONTROLS = 'button, a, input, select, textarea';
/** windows moved or resized snap to an invisible grid this fine, or to an edge this near (CSS pixels) */
const GRID = 16;
const SNAP_DISTANCE = 10;
/** windows this near to GAP apart count as snapped together (CSS pixels: rounding) */
const TOUCH_TOLERANCE = 1;
const BELOW = 'below';
const RIGHT = 'right';
const BOTTOM_EDGE = 'bottom';

/**
 * Windows over the 3D view (<twig:Plan:Window>, templates/components/Plan/Window.html.twig): each is dragged by its title bar
 * (or moved with the arrow keys on its grip), resized by its corner handle (or the arrow keys on it;
 * a double click gives it its own size back), minimised to its title bar, closed and opened again
 * from the dock. Where they are and which are open is kept in this browser (storageKey); a window
 * with data-window-auto opens by itself when that value changes to a non-empty one (an object was
 * selected). The live component re-renders the windows' contents: their place is applied again.
 *
 * A window moved or resized follows the pointer; an edge of another window (GAP beside it, or
 * lined up with it) or of the view that comes near pulls it on. Dropped elsewhere, it goes onto an
 * invisible grid (GRID). Alt places it freely. Dropped against
 * another one it joins its group (window_manager/groups.js): dragging, or moving with the arrow
 * keys, the group's anchor (its topmost window, the leftmost of those level) moves them all;
 * any other one takes the windows hanging under its bottom edge along (and those under them),
 * splitting off from the rest. Shift-drag moves a window alone, out of its group; closing it
 * takes it out too. Minimised (or back), a window's hanging windows follow its bottom edge. Groups are kept with the places.
 *
 * Below FLOATING the windows are not floating: one of them is shown under the view, the dock
 * switches between them like tabs.
 *
 *   <div data-controller="window-manager" data-window-manager-storage-key-value="…">
 *       <button data-window-manager-target="dock" data-window-manager-id-param="chat" data-action="window-manager#toggle">…</button>
 *       <section data-window-manager-target="window" data-window="chat" data-window-place="left top">…</section>
 *   </div>
 */
export default class extends Controller {
    static targets = ['window', 'dock', 'minimise', 'guideX', 'guideY'];
    static values = { storageKey: String };

    /** id → {x, y, w, h, open, minimised, group}; x/y null until placed, w/h null: its own size, group null: on its own */
    #state = {};
    /** on small screens: the window shown */
    #active = null;
    #floating = matchMedia(FLOATING);
    #drag = null;
    #resizing = null;
    #top = 20;
    #auto = {};
    #onMedia = () => this.#apply();

    async connect() {
        this.#state = this.#load();
        this.windowTargets.forEach((element) => {
            const id = element.dataset.window;
            this.#state[id] ??= { x: null, y: null, w: null, h: null, open: element.dataset.windowOpen !== 'false', minimised: false, group: null };
            this.#auto[id] = element.dataset.windowAuto ?? '';
        });
        // a group kept from before may have lost its other windows (closed, or gone from the page)
        const known = this.windowTargets.map((element) => element.dataset.window);
        this.#setGroups(normalise(Object.fromEntries(known.map((id) => [id, this.#state[id].open ? (this.#state[id].group ?? null) : null]))));
        this.#active = this.windowTargets.find((element) => this.#state[element.dataset.window].open)?.dataset.window ?? this.windowTargets[0]?.dataset.window ?? null;
        this.#floating.addEventListener('change', this.#onMedia);
        this.#apply();
        // the component's re-render brings the windows back as the server writes them: placed again
        const root = this.element.closest(LIVE_ROOT);
        if (root) {
            const component = await getComponent(root);
            component.on('render:finished', () => {
                this.#openWhereAsked();
                this.#apply();
            });
        }
    }

    disconnect() {
        this.#floating.removeEventListener('change', this.#onMedia);
    }

    /** a dock button: open (or bring forward) a closed window, close an open one */
    toggle({ params: { id } }) {
        const state = this.#state[id];
        if (!state) {
            return;
        }
        if (!this.#floating.matches) {
            this.#active = id;
            state.open = true;
        } else if (state.open && !state.minimised && this.#isFront(id)) {
            this.#shut(id);
        } else {
            state.open = true;
            state.minimised = false;
            this.#raise(id);
        }
        this.#save();
        this.#apply();
        if (state.open) {
            this.#window(id)?.querySelector('[data-window-body]')?.focus?.({ preventScroll: true });
        }
    }

    close({ params: { id } }) {
        if (this.#state[id]) {
            this.#shut(id);
            this.#save();
            this.#apply();
            this.dockTargets.find((button) => button.dataset.windowManagerIdParam === id)?.focus({ preventScroll: true });
        }
    }

    /** minimised to its title bar, or back: the windows of its group hanging under it follow its bottom edge */
    minimise({ params: { id } }) {
        if (!this.#state[id]) {
            return;
        }
        const hanging = this.#floating.matches ? this.#hangingUnder(id) : [];
        const before = this.#window(id)?.offsetHeight ?? 0;
        this.#state[id].minimised = !this.#state[id].minimised;
        this.#apply();
        const shift = (this.#window(id)?.offsetHeight ?? 0) - before;
        if (hanging.length > 0 && shift !== 0) {
            const starts = Object.fromEntries(hanging.map((member) => [member, this.#rect(member)]));
            this.#moveAll(starts, ...this.#clampDelta(starts, 0, shift));
        }
        this.#save();
    }

    /** the windows of its group hanging under its bottom edge, and under those in turn (not itself) */
    #hangingUnder(id) {
        return chainBelow(id, this.#moving(id), (above, below) => hangsUnder(this.#rect(above), this.#rect(below), GAP, TOUCH_TOLERANCE)).slice(1);
    }

    /** a press or the focus in a window brings it to the front */
    raise(event) {
        this.#raise(event.currentTarget.dataset.window);
    }

    grab(event) {
        if (!this.#floating.matches || event.button > 0 || event.target.closest(CONTROLS)) {
            return;
        }
        const element = event.currentTarget.closest('[data-window]');
        const id = element.dataset.window;
        event.preventDefault();
        event.currentTarget.setPointerCapture?.(event.pointerId);
        this.#detachFor(id, event.shiftKey);
        const moving = this.#moving(id);
        this.#drag = {
            id,
            pointer: event.pointerId,
            from: [event.clientX, event.clientY],
            starts: Object.fromEntries(moving.map((member) => [member, this.#rect(member)])),
            targets: this.#others(moving),
            moved: false,
        };
        const move = (e) => this.#move(e);
        const drop = (e) => {
            if (e.pointerId !== this.#drag?.pointer) {
                return;
            }
            const drag = this.#drag;
            this.#drag = null;
            this.#showGuides(null, null);
            // a press without a move (bringing it forward) neither snaps nor groups
            if (drag.moved) {
                this.#dropOnGrid(drag);
                this.#joinTouching(id);
            }
            this.#save();
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', drop);
            window.removeEventListener('pointercancel', drop);
        };
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', drop);
        window.addEventListener('pointercancel', drop);
    }

    /** a press on a window's corner handle: dragging it resizes the window */
    resize(event) {
        if (!this.#floating.matches || event.button > 0) {
            return;
        }
        const element = event.currentTarget.closest('[data-window]');
        const id = element.dataset.window;
        event.preventDefault();
        event.stopPropagation();
        event.currentTarget.setPointerCapture?.(event.pointerId);
        this.#raise(id);
        this.#resizing = {
            id,
            pointer: event.pointerId,
            from: [event.clientX, event.clientY],
            start: [element.offsetWidth, element.offsetHeight],
            targets: this.#others([id]),
            moved: false,
        };
        const move = (e) => this.#resizeMove(e);
        const drop = (e) => {
            if (e.pointerId !== this.#resizing?.pointer) {
                return;
            }
            const resizing = this.#resizing;
            this.#resizing = null;
            this.#showGuides(null, null);
            // a press without a move (or a double click: its own size) neither snaps nor groups
            if (resizing.moved) {
                this.#resizeOnGrid(resizing);
                this.#joinTouching(id);
            }
            this.#save();
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', drop);
            window.removeEventListener('pointercancel', drop);
        };
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', drop);
        window.addEventListener('pointercancel', drop);
    }

    /** the arrow keys on a window's corner handle: wider, narrower, taller, shorter */
    resizeByKey(event) {
        const move = MOVES[event.key];
        if (!move || !this.#floating.matches) {
            return;
        }
        event.preventDefault();
        const id = event.params.id;
        const element = this.#window(id);
        const step = event.shiftKey ? BIG_NUDGE : NUDGE;
        this.#setSize(id, element.offsetWidth + move[0] * step, element.offsetHeight + move[1] * step);
        this.#joinTouching(id);
        this.#save();
    }

    /** a double click on the corner handle: the window's own size again */
    resetSize({ params: { id } }) {
        if (this.#state[id]) {
            Object.assign(this.#state[id], { w: null, h: null });
            this.#save();
            this.#apply();
        }
    }

    /** the arrow keys on a window's grip move it */
    nudge(event) {
        const move = MOVES[event.key];
        if (!move || !this.#floating.matches) {
            return;
        }
        event.preventDefault();
        const id = event.params.id;
        const step = event.shiftKey ? BIG_NUDGE : NUDGE;
        this.#detachFor(id, false);
        const moving = this.#moving(id);
        const starts = Object.fromEntries(moving.map((member) => [member, this.#rect(member)]));
        this.#moveAll(starts, ...this.#clampDelta(starts, move[0] * step, move[1] * step));
        this.#joinTouching(id);
        this.#save();
        this.#apply();
    }

    /** the window was resized: every window stays inside it */
    fit() {
        this.#apply();
    }

    #move(event) {
        const drag = this.#drag;
        if (!drag || event.pointerId !== drag.pointer) {
            return;
        }
        let dx = event.clientX - drag.from[0];
        let dy = event.clientY - drag.from[1];
        drag.moved = true;
        let guides = [null, null];
        // it follows the pointer, but an edge near pulls it on (Alt: placed freely)
        drag.free = event.altKey;
        drag.snapped = [false, false];
        if (!drag.free) {
            const moved = Object.values(drag.starts).map((rect) => ({ ...rect, x: rect.x + dx, y: rect.y + dy }));
            const snap = snapMove(moved, drag.targets, this.#bounds(), { threshold: SNAP_DISTANCE, gap: GAP });
            dx += snap.x.delta ?? 0;
            dy += snap.y.delta ?? 0;
            drag.snapped = [snap.x.delta !== null, snap.y.delta !== null];
            guides = [snap.x.guide, snap.y.guide];
        }
        drag.delta = this.#clampDelta(drag.starts, dx, dy);
        this.#moveAll(drag.starts, ...drag.delta);
        this.#showGuides(...guides);
    }

    /** dropped: on an axis not snapped to an edge, the grabbed window's corner goes onto the grid (its group with it) */
    #dropOnGrid(drag) {
        if (drag.free) {
            return;
        }
        const lead = drag.starts[drag.id];
        const [dx, dy] = drag.delta;
        this.#moveAll(drag.starts, ...this.#clampDelta(
            drag.starts,
            drag.snapped[0] ? dx : toGrid(lead.x + dx, GRID) - lead.x,
            drag.snapped[1] ? dy : toGrid(lead.y + dy, GRID) - lead.y,
        ));
    }

    /** the windows from where they started, moved by dx, dy */
    #moveAll(starts, dx, dy) {
        Object.entries(starts).forEach(([id, rect]) => {
            const state = this.#state[id];
            [state.x, state.y] = [rect.x + dx, rect.y + dy];
            this.#position(this.#window(id), state);
        });
    }

    #resizeMove(event) {
        const resizing = this.#resizing;
        if (!resizing || event.pointerId !== resizing.pointer) {
            return;
        }
        resizing.moved = true;
        let width = resizing.start[0] + event.clientX - resizing.from[0];
        let height = resizing.start[1] + event.clientY - resizing.from[1];
        let guides = [null, null];
        // the corner follows the pointer, but an edge near pulls it on (Alt: freely)
        resizing.free = event.altKey;
        resizing.snapped = [false, false];
        if (!resizing.free) {
            const state = this.#state[resizing.id];
            const snap = snapResize({ x: state.x, y: state.y, w: width, h: height }, resizing.targets, this.#bounds(), { threshold: SNAP_DISTANCE, gap: GAP });
            width += snap.x.delta ?? 0;
            height += snap.y.delta ?? 0;
            resizing.snapped = [snap.x.delta !== null, snap.y.delta !== null];
            guides = [snap.x.guide, snap.y.guide];
        }
        resizing.size = [width, height];
        this.#setSize(resizing.id, width, height);
        this.#showGuides(...guides);
    }

    /** let go: on an axis not snapped to an edge, the corner goes onto the grid */
    #resizeOnGrid(resizing) {
        if (resizing.free) {
            return;
        }
        const state = this.#state[resizing.id];
        const [width, height] = resizing.size;
        this.#setSize(
            resizing.id,
            resizing.snapped[0] ? width : toGrid(state.x + width, GRID) - state.x,
            resizing.snapped[1] ? height : toGrid(state.y + height, GRID) - state.y,
        );
    }

    /** a window's size, kept inside the view from where it stands */
    #setSize(id, width, height) {
        const state = this.#state[id];
        const frame = this.element.getBoundingClientRect();
        const room = [frame.width - (state.x ?? 0) - MARGIN / 2, frame.height - (state.y ?? 0) - BOTTOM];
        state.w = Math.round(Math.max(MIN_WIDTH, Math.min(width, room[0])));
        state.h = state.minimised ? state.h : Math.round(Math.max(MIN_HEIGHT, Math.min(height, room[1])));
        this.#size(this.#window(id), state);
    }

    /** a size the person gave it, else its own (a minimised window is only its title bar) */
    #size(element, state) {
        if (state.w) {
            element.style.width = `${state.w}px`;
        } else {
            element.style.removeProperty('width');
        }
        if (state.h && !state.minimised) {
            element.style.height = `${state.h}px`;
        } else {
            element.style.removeProperty('height');
        }
    }

    /** windows whose auto value changed to a non-empty one open (an object was selected) */
    #openWhereAsked() {
        this.windowTargets.forEach((element) => {
            const id = element.dataset.window;
            const value = element.dataset.windowAuto ?? '';
            if (value !== this.#auto[id] && value !== '') {
                Object.assign(this.#state[id], { open: true, minimised: false });
                this.#active = this.#floating.matches ? this.#active : id;
                this.#raise(id);
                this.#save();
            }
            this.#auto[id] = value;
        });
    }

    #apply() {
        const floating = this.#floating.matches;
        this.windowTargets.forEach((element) => {
            const id = element.dataset.window;
            const state = this.#state[id];
            element.hidden = floating ? !state.open : id !== this.#active;
            const body = element.querySelector('[data-window-body]');
            if (body) {
                body.hidden = floating && state.minimised;
            }
            element.querySelector('[data-window-manager-target="minimise"]')?.setAttribute('aria-expanded', String(!(floating && state.minimised)));
            if (!floating) {
                element.style.removeProperty('left');
                element.style.removeProperty('top');
                element.style.removeProperty('z-index');
                element.style.removeProperty('width');
                element.style.removeProperty('height');

                return;
            }
            if (state.z) {
                element.style.zIndex = String(state.z);
            }
            this.#size(element, state);
        });
        if (floating) {
            // placed in order, so "below" another one finds it placed
            const shown = this.windowTargets.filter((element) => !element.hidden);
            shown.forEach((element) => {
                const state = this.#state[element.dataset.window];
                if (state.x === null || state.y === null) {
                    [state.x, state.y] = this.#defaultPlace(element);
                    // placed now, so the next one placed "below" it finds it
                    this.#position(element, state);
                }
            });
            // inside the view, a group as one (it keeps its shape)
            const done = new Set();
            shown.forEach((element) => {
                const id = element.dataset.window;
                if (done.has(id)) {
                    return;
                }
                const moving = this.#moving(id);
                moving.forEach((member) => done.add(member));
                const starts = Object.fromEntries(moving.map((member) => [member, this.#rect(member)]));
                this.#moveAll(starts, ...this.#clampDelta(starts, 0, 0));
            });
        }
        this.dockTargets.forEach((button) => {
            const id = button.dataset.windowManagerIdParam;
            const open = floating ? Boolean(this.#state[id]?.open) : id === this.#active;
            press(button, open);
        });
    }

    #position(element, state) {
        element.style.left = `${state.x}px`;
        element.style.top = `${state.y}px`;
        // it stays invisible until placed (Plan:Window), so it never shows stacked in a corner
        element.dataset.windowPlaced = '';
        // never under the dock: its content scrolls inside
        element.style.maxHeight = `${Math.max(MIN_HEIGHT, this.element.getBoundingClientRect().height - state.y - BOTTOM)}px`;
    }

    /** where a window opens first: data-window-place "left|right top|bottom", or "below <id>" */
    #defaultPlace(element) {
        const frame = this.element.getBoundingClientRect();
        const { width, height } = element.getBoundingClientRect();
        const [first, second] = (element.dataset.windowPlace ?? 'left top').split(' ');
        if (first === BELOW) {
            const above = this.#window(second);
            const state = this.#state[second];
            if (above && !above.hidden && state?.x !== null) {
                const x = state.x + above.offsetWidth - width;

                return this.#roomAt(element, x, Math.max(state.y + above.offsetHeight + GAP, ...this.#bottomsOnSide(element, x)));
            }

            return [frame.width - width - MARGIN, TOP];
        }
        const x = first === RIGHT ? frame.width - width - MARGIN : MARGIN;
        if (second !== BOTTOM_EDGE) {
            return [x, TOP];
        }
        // at the bottom, but below the windows already open on that side
        return this.#roomAt(element, x, Math.max(frame.height - height - BOTTOM, ...this.#bottomsOnSide(element, x)));
    }

    /** where each window already open on the side of x ends, a gap below it */
    #bottomsOnSide(element, x) {
        const half = this.element.getBoundingClientRect().width / 2;

        return this.windowTargets
            .filter((other) => other !== element && !other.hidden && this.#state[other.dataset.window]?.y !== null)
            .filter((other) => (this.#state[other.dataset.window].x < half) === (x < half))
            .map((other) => this.#state[other.dataset.window].y + other.offsetHeight + GAP);
    }

    /**
     * y below the windows already open, if enough of it is left above the dock; else the window
     * opens over them at the top, a step down for each one open on its side (it comes to the front).
     */
    #roomAt(element, x, y) {
        const frame = this.element.getBoundingClientRect();
        if (frame.height - BOTTOM - y >= MIN_ROOM) {
            return [x, y];
        }
        const left = x < frame.width / 2;
        const others = this.windowTargets.filter((other) => other !== element && !other.hidden
            && this.#state[other.dataset.window]?.y !== null
            && (this.#state[other.dataset.window].x < frame.width / 2) === left).length;
        this.#raise(element.dataset.window);

        return [x, TOP + others * CASCADE];
    }

    /** where a window's corner may be: inside the view, its title bar always reachable, above the dock ([minX, maxX, minY, maxY]) */
    #limits(id) {
        const element = this.#window(id);
        const frame = this.element.getBoundingClientRect();
        const width = element?.offsetWidth ?? 0;
        const header = element?.querySelector('[data-window-header]')?.offsetHeight ?? 40;

        return [MARGIN / 2, frame.width - width - MARGIN / 2, MARGIN / 2, frame.height - header - BOTTOM];
    }

    /** the move dx, dy of windows from where they started, cut short so each stays within its limits (all by the same, rounded) */
    #clampDelta(starts, dx, dy) {
        let [loX, hiX, loY, hiY] = [-Infinity, Infinity, -Infinity, Infinity];
        Object.entries(starts).forEach(([id, rect]) => {
            const [minX, maxX, minY, maxY] = this.#limits(id);
            loX = Math.max(loX, minX - rect.x);
            hiX = Math.min(hiX, maxX - rect.x);
            loY = Math.max(loY, minY - rect.y);
            hiY = Math.min(hiY, maxY - rect.y);
        });
        // a group wider or taller than the view: its left or top edge stays inside
        const within = (value, lo, hi) => (lo > hi ? lo : Math.min(Math.max(value, lo), hi));

        return [Math.round(within(dx, loX, hiX)), Math.round(within(dy, loY, hiY))];
    }

    /** where a window is, as a rectangle for snapping */
    #rect(id) {
        const element = this.#window(id);
        const state = this.#state[id];

        return { x: state.x ?? 0, y: state.y ?? 0, w: element?.offsetWidth ?? 0, h: element?.offsetHeight ?? 0 };
    }

    /** the edges windows snap to in the view: its sides, under its title bar, above the dock */
    #bounds() {
        const frame = this.element.getBoundingClientRect();

        return { left: MARGIN, top: TOP, right: frame.width - MARGIN, bottom: frame.height - BOTTOM };
    }

    /** the windows that move with this one (its group, those shown), itself included */
    #moving(id) {
        return members(this.#groups(), id).filter((member) => member === id || this.#isShown(member));
    }

    /** whether the window drags its group along: on its own, or the group's topmost (leftmost of those level) */
    #isAnchor(id) {
        const moving = this.#moving(id);

        return moving.length < 2 || anchor(moving, this.#state) === id;
    }

    /**
     * Before a window of a group moves: the anchor takes the whole group along; any other one the
     * windows hanging under it (and under those), splitting off from the rest; alone (Shift) none.
     */
    #detachFor(id, alone) {
        if (alone) {
            this.#setGroups(leave(this.#groups(), id));
        } else if (!this.#isAnchor(id)) {
            this.#setGroups(split(this.#groups(), [id, ...this.#hangingUnder(id)], () => this.#newGroup(id)));
        }
    }

    #newGroup(id) {
        return `group-${Date.now().toString(36)}-${id}`;
    }

    /** the rectangles of the windows shown, but these */
    #others(ids) {
        return this.windowTargets
            .map((element) => element.dataset.window)
            .filter((other) => !ids.includes(other) && this.#isShown(other))
            .map((other) => this.#rect(other));
    }

    /** the window (and its group) joins every window shown it now sits against */
    #joinTouching(id) {
        const moving = this.#moving(id);
        const touched = this.windowTargets
            .map((element) => element.dataset.window)
            .filter((other) => !moving.includes(other) && this.#isShown(other))
            .filter((other) => moving.some((member) => touching(this.#rect(member), this.#rect(other), GAP, TOUCH_TOLERANCE)));
        if (touched.length > 0) {
            this.#setGroups(join(this.#groups(), [id, ...touched], () => this.#newGroup(id)));
        }
    }

    /** a window closed: out of its group */
    #shut(id) {
        this.#state[id].open = false;
        this.#setGroups(leave(this.#groups(), id));
    }

    #groups() {
        return Object.fromEntries(Object.entries(this.#state).map(([id, { group = null }]) => [id, group]));
    }

    #setGroups(groups) {
        Object.entries(groups).forEach(([id, group]) => {
            if (this.#state[id]) {
                this.#state[id].group = group;
            }
        });
    }

    #isShown(id) {
        const element = this.#window(id);

        return Boolean(element && !element.hidden && this.#state[id]?.open);
    }

    /** the lines the window being moved or resized snapped to (null: none) */
    #showGuides(x, y) {
        [[this.hasGuideXTarget ? this.guideXTarget : null, x, 'left'], [this.hasGuideYTarget ? this.guideYTarget : null, y, 'top']].forEach(([guide, at, side]) => {
            if (!guide) {
                return;
            }
            guide.hidden = at === null;
            if (at !== null) {
                guide.style[side] = `${at}px`;
            }
        });
    }

    #raise(id) {
        const state = this.#state[id];
        if (!state || state.z === this.#top) {
            return;
        }
        state.z = ++this.#top;
        const element = this.#window(id);
        if (element && this.#floating.matches) {
            element.style.zIndex = String(state.z);
        }
    }

    #isFront(id) {
        return this.#state[id]?.z === this.#top;
    }

    #window(id) {
        return this.windowTargets.find((element) => element.dataset.window === id) ?? null;
    }

    #load() {
        try {
            const stored = JSON.parse(localStorage.getItem(this.storageKeyValue) ?? '{}');

            return stored && typeof stored === 'object' ? stored : {};
        } catch {
            return {};
        }
    }

    #save() {
        try {
            // the stacking order is this visit's
            const kept = Object.fromEntries(Object.entries(this.#state).map(([id, { x, y, w = null, h = null, open, minimised, group = null }]) => [id, { x, y, w, h, open, minimised, group }]));
            localStorage.setItem(this.storageKeyValue, JSON.stringify(kept));
        } catch {
            // storage blocked (private mode): the layout lasts for this page only
        }
    }
}
