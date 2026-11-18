import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';
import { press } from '../configurator/press.js';

/** the element of the live component the windows belong to (it re-renders them) */
const LIVE_ROOT = '[data-controller~="live"]';
/** windows float from this width on (Tailwind's lg); below, one is shown at a time under the view */
const FLOATING = '(min-width: 1024px)';
/** CSS pixels: room kept around windows, under the view's title bar, above the dock */
const MARGIN = 16;
const TOP = 76;
const BOTTOM = 72;
const GAP = 12;
/** a window is never squeezed below this height (CSS pixels) */
const MIN_HEIGHT = 120;
/** an arrow key on a window's grip moves it this far (Shift: further) */
const NUDGE = 16;
const BIG_NUDGE = 64;
const MOVES = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };
/** a press on these in a title bar is theirs, not a drag */
const CONTROLS = 'button, a, input, select, textarea';
const BELOW = 'below';
const RIGHT = 'right';
const BOTTOM_EDGE = 'bottom';

/**
 * Windows over the 3D view (<twig:Plan:Window>, templates/components/Plan/Window.html.twig): each is dragged by its title bar
 * (or moved with the arrow keys on its grip), minimised to its title bar, closed and opened again
 * from the dock. Where they are and which are open is kept in this browser (storageKey); a window
 * with data-window-auto opens by itself when that value changes to a non-empty one (an object was
 * selected). The live component re-renders the windows' contents: their place is applied again.
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
    static targets = ['window', 'dock', 'minimise'];
    static values = { storageKey: String };

    /** id → {x, y, open, minimised}; x/y null until placed */
    #state = {};
    /** on small screens: the window shown */
    #active = null;
    #floating = matchMedia(FLOATING);
    #drag = null;
    #top = 20;
    #auto = {};
    #onMedia = () => this.#apply();

    async connect() {
        this.#state = this.#load();
        this.windowTargets.forEach((element) => {
            const id = element.dataset.window;
            this.#state[id] ??= { x: null, y: null, open: element.dataset.windowOpen !== 'false', minimised: false };
            this.#auto[id] = element.dataset.windowAuto ?? '';
        });
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
            state.open = false;
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
            this.#state[id].open = false;
            this.#save();
            this.#apply();
            this.dockTargets.find((button) => button.dataset.windowManagerIdParam === id)?.focus({ preventScroll: true });
        }
    }

    minimise({ params: { id } }) {
        if (this.#state[id]) {
            this.#state[id].minimised = !this.#state[id].minimised;
            this.#save();
            this.#apply();
        }
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
        const state = this.#state[id];
        event.preventDefault();
        event.currentTarget.setPointerCapture?.(event.pointerId);
        this.#drag = { id, pointer: event.pointerId, from: [event.clientX, event.clientY], start: [state.x, state.y], handle: event.currentTarget };
        const move = (e) => this.#move(e);
        const drop = (e) => {
            if (e.pointerId !== this.#drag?.pointer) {
                return;
            }
            this.#drag = null;
            this.#save();
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', drop);
            window.removeEventListener('pointercancel', drop);
        };
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', drop);
        window.addEventListener('pointercancel', drop);
    }

    /** the arrow keys on a window's grip move it */
    nudge(event) {
        const move = MOVES[event.key];
        if (!move || !this.#floating.matches) {
            return;
        }
        event.preventDefault();
        const id = event.params.id;
        const state = this.#state[id];
        const step = event.shiftKey ? BIG_NUDGE : NUDGE;
        [state.x, state.y] = this.#clamp(id, state.x + move[0] * step, state.y + move[1] * step);
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
        const state = this.#state[drag.id];
        [state.x, state.y] = this.#clamp(drag.id, drag.start[0] + event.clientX - drag.from[0], drag.start[1] + event.clientY - drag.from[1]);
        this.#position(this.#window(drag.id), state);
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

                return;
            }
            if (state.z) {
                element.style.zIndex = String(state.z);
            }
        });
        if (floating) {
            // placed in order, so "below" another one finds it placed
            this.windowTargets.filter((element) => !element.hidden).forEach((element) => {
                const id = element.dataset.window;
                const state = this.#state[id];
                if (state.x === null || state.y === null) {
                    [state.x, state.y] = this.#defaultPlace(element);
                }
                [state.x, state.y] = this.#clamp(id, state.x, state.y);
                this.#position(element, state);
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
        // never past the bottom of the view: its content scrolls inside
        element.style.maxHeight = `${Math.max(MIN_HEIGHT, this.element.getBoundingClientRect().height - state.y - MARGIN)}px`;
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
                return [state.x + above.offsetWidth - width, state.y + above.offsetHeight + GAP];
            }

            return [frame.width - width - MARGIN, TOP];
        }
        const x = first === RIGHT ? frame.width - width - MARGIN : MARGIN;
        if (second !== BOTTOM_EDGE) {
            return [x, TOP];
        }
        // at the bottom, but below the windows already open on that side
        const above = this.windowTargets
            .filter((other) => other !== element && !other.hidden && this.#state[other.dataset.window]?.y !== null)
            .filter((other) => (this.#state[other.dataset.window].x < frame.width / 2) === (x < frame.width / 2))
            .map((other) => this.#state[other.dataset.window].y + other.offsetHeight + GAP);

        return [x, Math.max(frame.height - height - BOTTOM, ...above)];
    }

    /** inside the view, its title bar always reachable */
    #clamp(id, x, y) {
        const element = this.#window(id);
        const frame = this.element.getBoundingClientRect();
        const width = element?.offsetWidth ?? 0;
        const header = element?.querySelector('[data-window-header]')?.offsetHeight ?? 40;

        return [
            Math.round(Math.max(MARGIN / 2, Math.min(x, frame.width - width - MARGIN / 2))),
            Math.round(Math.max(MARGIN / 2, Math.min(y, frame.height - header - MARGIN / 2))),
        ];
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
            const kept = Object.fromEntries(Object.entries(this.#state).map(([id, { x, y, open, minimised }]) => [id, { x, y, open, minimised }]));
            localStorage.setItem(this.storageKeyValue, JSON.stringify(kept));
        } catch {
            // storage blocked (private mode): the layout lasts for this page only
        }
    }
}
