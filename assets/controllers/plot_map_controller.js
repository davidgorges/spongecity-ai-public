import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';
import { PlotMapView, area, crossesItself } from '../configurator/plot_map_view.js';

/** the element of the live component this map belongs to */
const LIVE_ROOT = '[data-controller~="live"]';
/** a pointer must move this far (CSS pixels) before a press becomes a drag */
const DRAG_THRESHOLD = 4;
const KEYS_REMOVE = ['Delete', 'Backspace'];
const KEYS_INSERT = ['Enter', ' '];
const MOVES = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };
const FORM_FIELDS = ['INPUT', 'SELECT', 'TEXTAREA'];
/** dispatched as plot-map:preview: the 3D view shows the outline at once */
const EVENT_PREVIEW = 'preview';
const FOCUS_OUT = 'focusout';
const POINTER_LEAVE = 'pointerleave';
const FOCUS_VISIBLE = ':focus-visible';
const HOVER = ':hover';

/**
 * The plot map: the analysed area in plan with the buildings around it. Its corners
 * are dragged (they line up with other corners and the buildings' corners; Alt: free),
 * nudged with the arrow keys, added in the middle of an edge and removed with a double
 * click or Delete. Every change is sent to the PlanWorkspace live component, whose
 * re-render brings the outline back as it was saved (the plot value).
 *
 * While a corner moves, and until the server has a change, the outline is announced as
 * plot-map:preview (detail: points in millimetres, or null for the saved one; editing:
 * the map is in use), so the 3D view reshapes and highlights the plot in real time.
 *
 *   <div data-controller="plot-map" data-plot-map-plot-value="[[x, z], …]" …>
 *       <svg data-plot-map-target="map" data-live-ignore></svg>
 *   </div>
 */
export default class extends Controller {
    static targets = ['map', 'handle', 'outline', 'tip', 'area', 'status', 'undo'];
    static values = {
        plot: Array,
        buildings: Array,
        action: String,
        minCorners: Number,
        maxCorners: Number,
        texts: Object,
    };

    /** the grid corners are put on, millimetres */
    static SNAP = 100;
    static BIG_SNAP = 1000;
    /** how near (CSS pixels) a corner must come to another corner to line up with it */
    static SNAP_PIXELS = 8;
    static MAX_HISTORY = 50;

    #view;
    /** the outline as last committed (sent, or as the server has it) */
    #points = [];
    #drag = null;
    #history = [];
    /** the server answered while a corner was held: the map follows it after the drop */
    #answerWaiting = false;
    /** the element to give the focus back to after a redraw: [kind, index] */
    #refocus = null;
    #resize;
    /** the outline the 3D view is shown instead of the saved one (null: the saved one) */
    #preview = null;
    /** the pointer is over the map or the focus in it */
    #engaged = false;

    connect() {
        this.#view = new PlotMapView(this.mapTarget, this.textsValue);
        this.#points = this.plotValue.map((p) => [...p]);
        this.#resize = new ResizeObserver(() => this.#drag || this.#draw());
        this.#resize.observe(this.mapTarget);
        this.#syncUndo();
    }

    disconnect() {
        this.#resize?.disconnect();
    }

    /** the re-render brought the plot as it is saved */
    plotValueChanged() {
        if (!this.#view) {
            return;
        }
        // the outline just sent, confirmed: drawn already (a redraw would take the focus off the handle)
        if (JSON.stringify(this.plotValue) === JSON.stringify(this.#points)) {
            return;
        }
        this.#points = this.plotValue.map((p) => [...p]);
        if (this.#drag) {
            this.#answerWaiting = true;

            return;
        }
        // the server's outline (saved, corrected, or changed by the agent): the 3D view has it in its scene
        this.#announce(null);
        this.#draw();
    }

    /** the map in use (pointer over it, focus in it): the plot in the 3D view lights up */
    engage() {
        this.#engaged = true;
        this.#announce(this.#preview);
    }

    /** the pointer left or the focus went: in use still while a keyboard user is on a handle */
    disengage(event) {
        const focused = event.type === FOCUS_OUT ? event.relatedTarget : document.activeElement;
        const keyboardInside = this.element.contains(focused) && focused.matches(FOCUS_VISIBLE);
        this.#engaged = keyboardInside || (event.type !== POINTER_LEAVE && this.element.matches(HOVER));
        if (!this.#drag) {
            this.#announce(this.#preview);
        }
    }

    buildingsValueChanged() {
        if (this.#view && !this.#drag) {
            this.#draw();
        }
    }

    /**
     * A finger on a handle drags it, not the page. touch-action: none says so, but
     * not every engine honours it on SVG children (WebKit); cancelling the touch works everywhere.
     */
    holdTouch(event) {
        event.preventDefault();
    }

    // dragging a corner, or an edge's middle (which adds a corner there)

    grab(event) {
        this.#startDrag(event, Number(event.params.corner), this.#points.map((p) => [...p]));
    }

    grabEdge(event) {
        if (this.#points.length >= this.maxCornersValue) {
            this.#say(this.textsValue.tooMany);
            event.preventDefault();

            return;
        }
        const edge = Number(event.params.edge);
        const points = this.#points.map((p) => [...p]);
        points.splice(edge + 1, 0, this.#middle(edge));
        this.#startDrag(event, edge + 1, points, true);
    }

    drag(event) {
        const drag = this.#drag;
        // the window hears every pointer: only the one that grabbed moves it
        if (!drag || event.pointerId !== drag.pointer) {
            return;
        }
        if (!drag.moved && Math.hypot(event.clientX - drag.from[0], event.clientY - drag.from[1]) < DRAG_THRESHOLD) {
            return;
        }
        drag.moved = true;
        drag.points[drag.index] = this.#snap(drag, this.#mapPoint(event), event.altKey);
        const invalid = crossesItself(drag.points);
        this.#view.render(drag.points, this.buildingsValue, { keepView: true, invalid, active: drag.index });
        this.#showTip(drag.points, drag.index, invalid);
        this.#announce(drag.points);
    }

    /** the browser took the gesture over (a scroll, a system gesture): nothing is saved */
    cancelDrag(event) {
        if (!this.#drag || (event.pointerId !== undefined && event.pointerId !== this.#drag.pointer)) {
            return;
        }
        this.#drag = null;
        this.#answerWaiting = false;
        this.#announce(null);
        this.#draw();
    }

    drop(event) {
        const drag = this.#drag;
        if (!drag || (event.pointerId !== undefined && event.pointerId !== drag.pointer)) {
            return;
        }
        this.#drag = null;
        if (!drag.moved) {
            // a click on an edge's middle adds the corner where it is; a click on a corner only focuses it
            if (drag.inserted) {
                this.#commit(drag.points, this.textsValue.added);
            } else if (this.#answerWaiting) {
                this.#draw();
            }
            this.#answerWaiting = false;

            return;
        }
        this.#answerWaiting = false;
        if (crossesItself(drag.points)) {
            this.#say(this.textsValue.crossing);
            this.#announce(null);
            this.#draw();

            return;
        }
        this.#commit(drag.points);
    }

    // the keyboard

    nudge(event) {
        const index = Number(event.params.corner);
        if (KEYS_REMOVE.includes(event.key)) {
            event.preventDefault();
            this.#remove(index);

            return;
        }
        const move = MOVES[event.key];
        if (!move) {
            return;
        }
        event.preventDefault();
        const distance = event.shiftKey ? this.constructor.BIG_SNAP : this.constructor.SNAP;
        const points = this.#points.map((p) => [...p]);
        points[index] = [points[index][0] + move[0] * distance, points[index][1] + move[1] * distance];
        if (crossesItself(points)) {
            this.#say(this.textsValue.crossing);

            return;
        }
        this.#refocus = ['corner', index];
        this.#commit(points);
    }

    insertByKey(event) {
        if (!KEYS_INSERT.includes(event.key)) {
            return;
        }
        event.preventDefault();
        if (this.#points.length >= this.maxCornersValue) {
            this.#say(this.textsValue.tooMany);

            return;
        }
        const edge = Number(event.params.edge);
        const points = this.#points.map((p) => [...p]);
        points.splice(edge + 1, 0, this.#middle(edge));
        this.#refocus = ['corner', edge + 1];
        this.#commit(points, this.textsValue.added);
    }

    removeCorner(event) {
        event.preventDefault();
        this.#remove(Number(event.params.corner));
    }

    shortcut(event) {
        if (!(event.ctrlKey || event.metaKey) || event.shiftKey || event.key.toLowerCase() !== 'z' || FORM_FIELDS.includes(event.target.tagName)) {
            return;
        }
        event.preventDefault();
        this.undo();
    }

    undo() {
        const previous = this.#history.pop();
        if (previous === undefined) {
            return;
        }
        this.#send(previous, this.textsValue.undone);
    }

    // internals

    #startDrag(event, index, points, inserted = false) {
        if (this.#drag || event.button > 0) {
            return;
        }
        event.preventDefault();
        event.currentTarget.setPointerCapture?.(event.pointerId);
        event.currentTarget.focus({ preventScroll: true });
        this.#refocus = ['corner', index];
        this.#drag = { pointer: event.pointerId, from: [event.clientX, event.clientY], index, points, inserted, moved: false };
    }

    #remove(index) {
        if (this.#points.length <= this.minCornersValue) {
            this.#say(this.textsValue.tooFew);

            return;
        }
        const points = this.#points.filter((_, i) => i !== index);
        if (crossesItself(points)) {
            this.#say(this.textsValue.crossing);

            return;
        }
        this.#refocus = ['corner', Math.min(index, points.length - 1)];
        this.#commit(points, this.textsValue.removed);
    }

    #middle(edge) {
        const [x1, z1] = this.#points[edge];
        const [x2, z2] = this.#points[(edge + 1) % this.#points.length];
        const step = this.constructor.SNAP;

        return [Math.round((x1 + x2) / 2 / step) * step, Math.round((z1 + z2) / 2 / step) * step];
    }

    /** A change by the person: remembered for undo, then sent. */
    #commit(points, announcement = null) {
        if (JSON.stringify(points) === JSON.stringify(this.#points)) {
            this.#announce(null);
            this.#draw();

            return;
        }
        this.#history.push(this.#points.map((p) => [...p]));
        this.#history.splice(0, Math.max(0, this.#history.length - this.constructor.MAX_HISTORY));
        this.#send(points, announcement);
    }

    /** Draws the outline right away and saves it; the re-render confirms it (or corrects it). */
    async #send(points, announcement = null) {
        this.#points = points.map((p) => [...p]);
        // the 3D view keeps it until the server's answer brings it in the scene
        this.#announce(this.#points);
        this.#draw();
        this.#syncUndo();
        this.#say(announcement ?? this.textsValue.saved.replace('%area%', this.#view.squareMetres(area(points))));
        const component = await getComponent(this.element.closest(LIVE_ROOT));
        await component.action(this.actionValue, { points });
    }

    #announce(points) {
        this.#preview = points;
        this.dispatch(EVENT_PREVIEW, { detail: { points, editing: this.#engaged || this.#drag !== null } });
    }

    #draw() {
        if (this.#points.length < this.minCornersValue) {
            return;
        }
        this.#view.render(this.#points, this.buildingsValue);
        if (this.hasAreaTarget) {
            this.areaTarget.textContent = this.#view.squareMetres(area(this.#points));
        }
        this.#restoreFocus();
    }

    #restoreFocus() {
        const refocus = this.#refocus;
        this.#refocus = null;
        // only when the focus was on the map (the redraw dropped it to the body), never taken from elsewhere
        if (!refocus || (document.activeElement !== document.body && !this.element.contains(document.activeElement))) {
            return;
        }
        this.handleTargets[refocus[1]]?.focus({ preventScroll: true });
    }

    #syncUndo() {
        if (this.hasUndoTarget) {
            this.undoTarget.disabled = this.#history.length === 0;
        }
    }

    /**
     * Where a dragged corner goes: lined up (x or z) with another corner of the plot or a
     * building's when close, on a 10 cm grid; Alt: free.
     */
    #snap(drag, { x, y: z }, free) {
        const step = this.constructor.SNAP;
        const round = (value) => Math.round(value / step) * step;
        if (free) {
            return [round(x), round(z)];
        }
        const tolerance = this.constructor.SNAP_PIXELS * this.#unitsPerPixel();
        let bestX = tolerance;
        let bestZ = tolerance;
        let snappedX = null;
        let snappedZ = null;
        const corners = [...drag.points.filter((_, i) => i !== drag.index), ...this.buildingsValue.flat()];
        for (const [px, pz] of corners) {
            if (Math.abs(px - x) < bestX) {
                bestX = Math.abs(px - x);
                snappedX = px;
            }
            if (Math.abs(pz - z) < bestZ) {
                bestZ = Math.abs(pz - z);
                snappedZ = pz;
            }
        }

        return [snappedX ?? round(x), snappedZ ?? round(z)];
    }

    #unitsPerPixel() {
        const width = this.mapTarget.viewBox.baseVal.width || 50000;

        return width / Math.max(1, this.mapTarget.clientWidth);
    }

    #mapPoint(event) {
        const svg = this.mapTarget;
        const point = svg.createSVGPoint();
        point.x = event.clientX;
        point.y = event.clientY;

        return point.matrixTransform(svg.getScreenCTM().inverse());
    }

    /** The dragged corner's two edges and the plot's area, next to it. */
    #showTip(points, index, invalid) {
        if (!this.hasTipTarget) {
            return;
        }
        const count = points.length;
        const at = points[index];
        const before = points[(index - 1 + count) % count];
        const after = points[(index + 1) % count];
        const parts = invalid
            ? [this.textsValue.crossingShort]
            : [
                this.#view.metres(Math.hypot(at[0] - before[0], at[1] - before[1])),
                this.#view.metres(Math.hypot(after[0] - at[0], after[1] - at[1])),
                this.#view.squareMetres(area(points)),
            ];
        const tip = this.tipTarget;
        const font = Number(tip.dataset.font);
        const label = tip.querySelector('text');
        label.textContent = parts.join(' · ');
        const width = label.textContent.length * font * 0.56 + font;
        tip.querySelector('rect').setAttribute('width', String(width));
        label.setAttribute('x', String(font * 0.5));
        label.setAttribute('y', String(font * 0.85));
        // beside the corner, inside the frame
        const frame = this.mapTarget.viewBox.baseVal;
        const x = Math.min(Math.max(at[0] + font, frame.x + font * 0.3), frame.x + frame.width - width - font * 0.3);
        const y = Math.min(Math.max(at[1] - font * 2.6, frame.y + font * 0.3), frame.y + frame.height - font * 2);
        tip.setAttribute('transform', `translate(${x} ${y})`);
        tip.setAttribute('visibility', 'visible');
    }

    #say(message) {
        if (this.hasStatusTarget && message) {
            this.statusTarget.textContent = message;
        }
    }
}
