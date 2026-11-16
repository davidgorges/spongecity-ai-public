import { allRuns, cornerAngle, gates, houseCorners, houseWidth, mainRun, vertices } from './fence_plan.js';

const SVG = 'http://www.w3.org/2000/svg';
const CONTROLLER = 'fence-layout';
/** nice lengths for the scale bar, millimetres */
const SCALES = [1000, 2000, 5000, 10000, 20000];
/** the least room around the line in the frame, millimetres (a length label is about 2 m wide) */
const FRAME_MIN_MARGIN = 2000;
/** the on-screen font of the plan's text, CSS pixels: a share of the frame, within these bounds */
const FONT_SHARE = 0.026;
const FONT_MIN = 10;
const FONT_MAX = 13;
/** how far behind the fence the plan shows the house, millimetres */
const HOUSE_REACH = 3500;
/** the smallest a touch target may be, CSS pixels: WCAG 2.5.8 with a mouse, a fingertip on touch screens */
const MIN_TARGET = 24;
const MIN_TOUCH_TARGET = 44;
/** any finger on the device (a touch-screen laptop's primary pointer is the trackpad) */
const COARSE_POINTER = '(any-pointer: coarse)';
/** how a picked house is marked, here and by the controller (a grab marks the element without a redraw) */
export const PICKED_ATTRIBUTE = 'data-selected';
export const PICKED_CLASS = 'touch-none';

/**
 * Draws the fence line in plan into an SVG (house on top, street below):
 * a 1 m grid, the house, the runs with their lengths, angles at corners
 * other than right angles, gate openings with their leaves, a handle on
 * every corner and a scale bar. Interaction stays with the fence-layout
 * controller: the elements carry its data-action attributes and params.
 */
export class FencePlanView {
    #svg;
    #rules;
    #texts;
    #format;
    #viewBox = null;
    /** read once: every drag frame redraws the plan */
    #coarse = matchMedia(COARSE_POINTER);
    #id;

    /**
     * @param {SVGSVGElement} svg
     * @param {object} rules  constants of the PHP classes (see fence_plan.js)
     * @param {object} texts  translated labels: house, street, corner, gate, length (with %run%), gateTypes {single, double}
     */
    constructor(svg, rules, texts) {
        this.#svg = svg;
        this.#rules = rules;
        this.#texts = texts;
        this.#id = svg.id || 'fence-plan';
        this.#format = new Intl.NumberFormat(document.documentElement.lang || undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    metres(millimetres) {
        return `${this.#format.format(millimetres / 1000)} m`;
    }

    /**
     * @param {object} layout the fence line (FenceLayout JSON)
     * @param {{active?: number|null, names?: string[], keepView?: boolean, points?: number[][]|null, site?: {outlines?: number[][][], house?: boolean}}} options
     *        keepView: keep the frame (while dragging, so the plan does not jump);
     *        points: draw these corners instead of laying the line out (a corner being dragged);
     *        site: the customer's surroundings, drawn as outlines; with their own house, ours is left out;
     *        houseSelected: the house is picked (touch: only then it drags, so a swipe over it scrolls the page)
     * @returns {number[][]} the vertices drawn
     */
    render(layout, { active = null, names = [], keepView = false, points: given = null, site = {}, houseSelected = false } = {}) {
        const rules = this.#rules;
        const points = given ?? vertices(layout, rules);
        const runs = allRuns(layout);
        const house = site.house ? [] : houseCorners(layout, rules);
        const outlines = site.outlines ?? [];
        const openings = gates(layout, points, rules);
        const count = points.length;

        if (!keepView || !this.#viewBox) {
            // the fence fills the frame; of the house and the surroundings only what is near it (they can stand
            // far behind), all of the house while it is picked (it is being moved or turned)
            const reach = Math.min(...points.map(([, z]) => z)) - HOUSE_REACH;
            const behind = ([x, z]) => [x, Math.max(z, reach)];
            // the box's own shape: 4:3 in the page, taller in the full-screen plan
            const box = this.#svg.clientWidth > 0 && this.#svg.clientHeight > 0 ? this.#svg.clientWidth / this.#svg.clientHeight : 4 / 3;
            this.#viewBox = this.#frame([...points, ...(houseSelected ? house : house.map(behind)), ...outlines.flat().map(behind)], box, this.#svg.clientWidth);
        }
        const [minX, minZ, width, height] = this.#viewBox;
        const extent = Math.max(width, height);
        const pixels = this.#svg.clientWidth > 0 ? this.#svg.clientWidth / width : 0.05;
        // text and dots keep a readable size on screen whatever the box's shape (about 10–13 px and 6–9 px)
        const onScreen = (share, min, max) => (pixels > 0 ? Math.min(max, Math.max(min, extent * share * pixels)) / pixels : extent * share);
        const font = onScreen(FONT_SHARE, FONT_MIN, FONT_MAX);
        const handle = onScreen(0.016, 6, 9);
        const target = this.#coarse.matches ? MIN_TOUCH_TARGET : MIN_TARGET;
        const hit = Math.max(handle * 1.6, target / 2 / pixels);
        const street = Math.max(...points.map(([, z]) => z), ...house.map(([, z]) => z)) + rules.streetGap;

        this.#svg.setAttribute('viewBox', `${minX} ${minZ} ${width} ${height}`);
        this.#svg.setAttribute('overflow', 'hidden');
        this.#svg.replaceChildren();

        // a 1 m grid
        const defs = el('defs');
        const pattern = el('pattern', { id: `${this.#id}-grid`, width: 1000, height: 1000, patternUnits: 'userSpaceOnUse', x: 0, y: 0 });
        pattern.append(el('path', { d: 'M1000 0H0V1000', fill: 'none', class: 'stroke-border', 'stroke-width': 1, 'vector-effect': 'non-scaling-stroke' }));
        defs.append(pattern);
        this.#svg.append(defs, el('rect', { x: minX, y: minZ, width, height, fill: `url(#${this.#id}-grid)`, class: 'pointer-events-none' }));

        outlines.forEach((outline) => this.#svg.append(el('polygon', {
            points: outline.map(([x, z]) => `${x},${z}`).join(' '),
            class: 'pointer-events-none fill-muted-foreground/10 stroke-muted-foreground/40',
            'stroke-width': 1,
            'vector-effect': 'non-scaling-stroke',
        })));
        if (!site.house) {
            this.#svg.append(this.#house(layout, font, houseSelected));
        }
        this.#svg.append(
            el('line', { x1: minX, y1: street, x2: minX + width, y2: street, class: 'pointer-events-none stroke-muted-foreground/50', 'stroke-width': 1.5, 'stroke-dasharray': '6 4', 'vector-effect': 'non-scaling-stroke' }),
            text(this.#texts.street, { x: minX + font, y: street + font * 1.3, 'font-size': font * 0.85, class: 'pointer-events-none fill-muted-foreground' }),
        );

        // runs: a wide invisible line to point at, the visible one on top
        runs.forEach((run, i) => {
            const [x1, z1] = points[i];
            const [x2, z2] = points[(i + 1) % count];
            const line = { x1, y1: z1, x2, y2: z2, 'vector-effect': 'non-scaling-stroke', 'stroke-linecap': 'round' };
            const name = names[i] ?? '';
            this.#svg.append(
                el('line', {
                    ...line,
                    class: 'cursor-pointer stroke-transparent',
                    // pointing at a run opens its card: a narrow band, so a missed corner or a tap on the
                    // background does not (the 44 px touch targets are for what drags)
                    'stroke-width': Math.max(14, MIN_TARGET),
                    'data-action': `mouseenter->${CONTROLLER}#highlight mouseleave->${CONTROLLER}#unhighlight click->${CONTROLLER}#selectRun`,
                    [`data-${CONTROLLER}-run-param`]: i,
                }),
                el('line', {
                    ...line,
                    class: 'pointer-events-none stroke-foreground transition-colors data-active:stroke-primary',
                    'stroke-width': 3,
                    'data-fence-layout-target': 'planRun',
                    'data-run': i,
                    ...(active === i ? { 'data-active': '' } : {}),
                }),
            );
            if (name) {
                this.#svg.lastChild.append(el('title', {}, name));
            }
        });

        openings.forEach((gate, index) => this.#svg.append(this.#gate(gate, index, font, target / 2 / pixels)));

        // lengths, outside every run; entered ones can be edited in place
        runs.forEach((run, i) => {
            const [x1, z1] = points[i];
            const [x2, z2] = points[(i + 1) % count];
            const length = Math.max(1, Math.hypot(x2 - x1, z2 - z1));
            const content = this.metres(length);
            // the label's touch target is a box level with the screen: how far it reaches across the run and along it
            const [boxWidth, boxHeight] = this.#labelBox(content, font, target / pixels);
            const [dx, dz] = [(x2 - x1) / length, (z2 - z1) / length];
            const across = (boxWidth / 2) * Math.abs(dz) + (boxHeight / 2) * Math.abs(dx);
            const lengthwise = (boxWidth / 2) * Math.abs(dx) + (boxHeight / 2) * Math.abs(dz);
            // outside the run, far enough that the whole target stays off the run line (and off a gate in it)
            const offset = Math.max(font * 1.4, across + font * 0.2);
            // in the middle of the run, or beside its gate with the target's edge clear of the opening
            const gate = openings.find((opening) => opening.run === i);
            const along = this.#labelAlong(gate, [x1, z1], length, lengthwise, gate ? this.#gateReach(gate, font, target / 2 / pixels) : 0);
            const x = x1 + dx * along - dz * offset;
            const z = z1 + dz * along + dx * offset;
            const editable = !run.closing && !(layout.shape === 'rectangle' && i === 2);
            this.#svg.append(this.#label(content, x, z, font, editable ? i : null, names[i] ?? '', target / pixels));
        });

        // angles at corners that are neither right angles nor straight on
        points.forEach((point, i) => {
            const before = points[i - 1] ?? (layout.closed ? points[count - 1] : null);
            const after = points[i + 1] ?? (layout.closed ? points[0] : null);
            if (!before || !after) {
                return;
            }
            const angle = Math.round(cornerAngle(before, point, after));
            if (Math.abs(angle - 90) < 1 || angle > 179) {
                return;
            }
            // inside the corner: along the bisector
            const a = unit(before[0] - point[0], before[1] - point[1]);
            const b = unit(after[0] - point[0], after[1] - point[1]);
            const bisector = unit(a[0] + b[0], a[1] + b[1]);
            this.#svg.append(text(`${angle}°`, {
                x: point[0] + bisector[0] * font * 2.2,
                y: point[1] + bisector[1] * font * 2.2,
                'font-size': font * 0.9,
                'text-anchor': 'middle',
                'dominant-baseline': 'central',
                class: 'pointer-events-none fill-muted-foreground tabular-nums',
            }));
        });

        // corner handles: a target of at least 24 px around the visible dot
        points.forEach(([x, z], i) => {
            const group = el('g', {
                transform: `translate(${x} ${z})`,
                // touch-none: a finger on a corner drags it (the rest of the plan lets the page scroll)
                class: 'group/handle cursor-grab touch-none outline-none active:cursor-grabbing',
                tabindex: 0,
                role: 'button',
                'aria-label': this.#texts.corner.replace('%number%', i + 1),
                'data-fence-layout-target': 'handle',
                'data-x': Math.round(x),
                'data-z': Math.round(z),
                'data-front': i === mainRun(layout) || i === (mainRun(layout) + 1) % count ? 'true' : 'false',
                'data-action': `touchstart->${CONTROLLER}#holdTouch:!passive pointerdown->${CONTROLLER}#grab keydown->${CONTROLLER}#nudge`,
                [`data-${CONTROLLER}-vertex-param`]: i,
            });
            group.append(
                el('circle', { r: hit, class: 'fill-transparent' }),
                el('circle', { r: handle, class: 'fill-background stroke-primary transition-colors group-hover/handle:fill-accent group-focus-visible/handle:fill-primary', 'stroke-width': 2, 'vector-effect': 'non-scaling-stroke' }),
            );
            this.#svg.append(group);
        });

        this.#svg.append(this.#scaleBar(minX, minZ, width, height, font));
        this.#svg.append(this.#tip(font));

        return points;
    }

    #frame(points, aspect = 4 / 3, boxWidth = 0) {
        const xs = points.map(([x]) => x);
        const zs = points.map(([, z]) => z);
        const minX = Math.min(...xs);
        const maxX = Math.max(...xs);
        const minZ = Math.min(...zs);
        const maxZ = Math.max(...zs) + this.#rules.streetGap;
        // room for the length labels outside the runs: a share of the line and at least FRAME_MIN_MARGIN; on screen at
        // least the room a label needs on that side. The box's shape often gives one axis room to spare; only an axis
        // short of room grows (the scale follows from the frame, so a few rounds)
        const base = Math.max(Math.max(maxX - minX, maxZ - minZ) * 0.1, FRAME_MIN_MARGIN);
        let marginX = base;
        let marginZ = base;
        let width;
        let height;
        for (let round = 0; round < 5; ++round) {
            // the shape of the box the plan is drawn in, the line centred
            width = maxX - minX + 2 * marginX;
            height = maxZ - minZ + 2 * marginZ;
            if (width / height < aspect) {
                width = height * aspect;
            } else {
                height = width / aspect;
            }
            if (boxWidth <= 0) {
                break;
            }
            const perPixel = width / boxWidth;
            // a length label as the render will draw it: beside a side run it takes its pill's width twice over
            // (half its touch target as the gap, then the pill), above or below a run its touch target and its pill
            const font = Math.min(FONT_MAX, Math.max(FONT_MIN, Math.max(width, height) * FONT_SHARE / perPixel));
            const roomAcross = 5.2 * font + 4;
            const roomAlong = MIN_TOUCH_TARGET / 2 + 1.2 * font;
            const short = [(width - (maxX - minX)) / 2 < roomAcross * perPixel, (height - (maxZ - minZ)) / 2 < roomAlong * perPixel];
            if (!short[0] && !short[1]) {
                break;
            }
            if (short[0]) {
                marginX = roomAcross * perPixel;
            }
            if (short[1]) {
                marginZ = roomAlong * perPixel;
            }
        }

        return [(minX + maxX) / 2 - width / 2, (minZ + maxZ) / 2 - height / 2, width, height].map(Math.round);
    }

    #house(layout, font, selected) {
        const house = { x: 0, z: 0, turn: 0, ...layout.house };
        const width = houseWidth(layout, this.#rules);
        const depth = house.depth ?? this.#rules.houseDepth;
        const group = el('g', {
            transform: `translate(${house.x} ${house.z}) rotate(${-house.turn})`,
            // picked (data-selected), it takes the finger (touch-none); otherwise a swipe over it scrolls the page
            class: `group/house cursor-move outline-none ${selected ? PICKED_CLASS : ''}`,
            tabindex: 0,
            role: 'button',
            'aria-label': this.#texts.houseHandle,
            'data-fence-layout-target': 'house',
            'data-x': house.x,
            'data-z': house.z,
            'data-turn': house.turn,
            ...(selected ? { [PICKED_ATTRIBUTE]: '' } : {}),
            // picked or not, for screen readers too (picking shows the turn buttons)
            'aria-pressed': selected ? 'true' : 'false',
            'data-action': `touchstart->${CONTROLLER}#holdTouch:!passive pointerdown->${CONTROLLER}#grabHouse click->${CONTROLLER}#selectHouse keydown->${CONTROLLER}#nudgeHouse`,
        });
        group.append(
            el('rect', { x: -width / 2, y: -depth, width, height: depth, class: 'fill-muted-foreground/15 stroke-muted-foreground/40 transition-colors group-hover/house:fill-muted-foreground/25 group-focus-visible/house:fill-primary/20 group-focus-visible/house:stroke-primary group-data-selected/house:fill-primary/15 group-data-selected/house:stroke-primary', 'stroke-width': 1.5, 'vector-effect': 'non-scaling-stroke' }),
            el('line', { x1: -width / 2, y1: 0, x2: width / 2, y2: 0, class: 'stroke-muted-foreground group-data-selected/house:stroke-primary', 'stroke-width': 3, 'vector-effect': 'non-scaling-stroke' }),
            // the name stays upright however the house is turned
            text(this.#texts.house, { x: 0, y: -depth / 2, transform: `rotate(${house.turn} 0 ${-depth / 2})`, 'font-size': font, 'text-anchor': 'middle', 'dominant-baseline': 'middle', class: 'pointer-events-none fill-muted-foreground' }),
        );

        return group;
    }

    /**
     * Where along a run (from its start) its length goes: the middle, or the middle of the longer part
     * beside its gate, but never so close that the label's target (reach: half its extent along the run)
     * covers the opening.
     */
    #labelAlong(gate, start, length, reach = 0, gateReach = 0) {
        if (!gate) {
            return length / 2;
        }
        // the gate's own target (a circle about its middle) is what must stay free, not only the opening
        const half = Math.max(gate.width / 2, gateReach);
        const at = Math.hypot(gate.centre[0] - start[0], gate.centre[1] - start[1]);
        const before = at - half;
        const after = length - at - half;

        return before >= after ? Math.min(before / 2, before - reach) : Math.max(length - after / 2, length - after + reach);
    }

    /** A gate: the opening, its leaves swung open towards the garden, draggable along the fence. */
    #gate(gate, index, font, minRadius) {
        const [dx, dz] = gate.direction;
        // inside, towards the garden: (dz, -dx)
        const inside = [dz, -dx];
        const leaves = gate.type === 'double' ? [[gate.from, 1], [gate.to, -1]] : [[gate.from, 1]];
        const leaf = gate.type === 'double' ? gate.width / 2 : gate.width;
        const group = el('g', {
            class: 'group/gate cursor-grab touch-none outline-none active:cursor-grabbing',
            tabindex: 0,
            role: 'button',
            'aria-label': this.#texts.gate.replace('%gate%', this.#texts.gateTypes[gate.type] ?? gate.type),
            'data-fence-layout-target': 'gateHandle',
            'data-action': `touchstart->${CONTROLLER}#holdTouch:!passive pointerdown->${CONTROLLER}#grabGate keydown->${CONTROLLER}#nudgeGate`,
            [`data-${CONTROLLER}-gate-param`]: index,
        });
        group.append(el('line', { x1: gate.from[0], y1: gate.from[1], x2: gate.to[0], y2: gate.to[1], class: 'stroke-background', 'stroke-width': 6, 'vector-effect': 'non-scaling-stroke' }));
        for (const [hinge, sign] of leaves) {
            const open = [hinge[0] + inside[0] * leaf, hinge[1] + inside[1] * leaf];
            const closed = [hinge[0] + sign * dx * leaf, hinge[1] + sign * dz * leaf];
            const sweep = sign > 0 ? 0 : 1;
            group.append(
                el('line', { x1: hinge[0], y1: hinge[1], x2: open[0], y2: open[1], class: 'stroke-primary', 'stroke-width': 2, 'vector-effect': 'non-scaling-stroke' }),
                el('path', { d: `M${closed[0]} ${closed[1]} A${leaf} ${leaf} 0 0 ${sweep} ${open[0]} ${open[1]}`, fill: 'none', class: 'stroke-primary/60', 'stroke-width': 1.5, 'stroke-dasharray': '4 3', 'vector-effect': 'non-scaling-stroke' }),
            );
        }
        // a generous target over the opening
        group.append(el('circle', { cx: gate.centre[0], cy: gate.centre[1], r: this.#gateReach(gate, font, minRadius), class: 'fill-transparent group-focus-visible/gate:fill-primary/15' }));

        return group;
    }

    /** A length label's touch target, width and height in plan units (the pill, at least minTarget either way). */
    #labelBox(content, font, minTarget) {
        const [width, height] = this.#pill(content, font);

        return [Math.max(width, minTarget, height), Math.max(height, minTarget)];
    }

    /** The radius of a gate's touch target about its middle, in plan units. */
    #gateReach(gate, font, minRadius) {
        return Math.max(gate.width / 2, font * 1.5, minRadius);
    }

    /** The visible pill of a length label, width and height in plan units. */
    #pill(content, font) {
        return [content.length * font * 0.58 + font * 0.9, font * 1.6];
    }

    #label(content, x, z, font, run, name, minTarget) {
        const [width, height] = this.#pill(content, font);
        const target = Math.max(height, minTarget);
        const attributes = { transform: `translate(${x} ${z})`, class: 'group/label' };
        if (run !== null) {
            Object.assign(attributes, {
                class: 'group/label cursor-text outline-none',
                tabindex: 0,
                role: 'button',
                'aria-label': this.#texts.length.replace('%run%', name),
                'data-fence-layout-target': 'lengthLabel',
                'data-run': run,
                'data-action': `click->${CONTROLLER}#editLength keydown.enter->${CONTROLLER}#editLength`,
                [`data-${CONTROLLER}-run-param`]: run,
            });
        }
        const group = el('g', attributes);
        group.append(
            el('rect', { x: -Math.max(width, target) / 2, y: -target / 2, width: Math.max(width, target), height: target, class: 'fill-transparent' }),
            el('rect', { x: -width / 2, y: -height / 2, width, height, rx: height / 2, class: run === null ? 'fill-muted stroke-border' : 'fill-background stroke-border transition-colors group-hover/label:stroke-primary group-focus-visible/label:stroke-primary', 'vector-effect': 'non-scaling-stroke' }),
            text(content, { 'font-size': font, 'text-anchor': 'middle', 'dominant-baseline': 'central', class: 'pointer-events-none fill-foreground tabular-nums' }),
        );

        return group;
    }

    #scaleBar(minX, minZ, width, height, font) {
        const length = SCALES.find((scale) => scale >= width / 6) ?? SCALES[SCALES.length - 1];
        const x = minX + width - length - font;
        const y = minZ + height - font;
        const group = el('g', { class: 'pointer-events-none', 'aria-hidden': 'true' });
        group.append(
            el('path', { d: `M${x} ${y - font * 0.4}V${y}H${x + length}V${y - font * 0.4}`, fill: 'none', class: 'stroke-muted-foreground', 'stroke-width': 1.5, 'vector-effect': 'non-scaling-stroke' }),
            text(`${length / 1000} m`, { x: x + length / 2, y: y - font * 0.6, 'font-size': font * 0.8, 'text-anchor': 'middle', class: 'fill-muted-foreground' }),
        );

        return group;
    }

    /** The live readout while dragging (filled by the controller). */
    #tip(font) {
        const group = el('g', { 'data-fence-layout-target': 'tip', class: 'pointer-events-none', visibility: 'hidden' });
        group.append(
            el('rect', { height: font * 1.7, rx: font * 0.4, class: 'fill-foreground' }),
            text('', { 'font-size': font, 'dominant-baseline': 'central', class: 'fill-background tabular-nums' }),
        );
        group.dataset.font = String(font);

        return group;
    }
}

function el(name, attributes = {}, content = null) {
    const node = document.createElementNS(SVG, name);
    for (const [key, value] of Object.entries(attributes)) {
        node.setAttribute(key, String(value));
    }
    if (content !== null) {
        node.textContent = content;
    }

    return node;
}

function text(content, attributes) {
    return el('text', attributes, content);
}

function unit(x, z) {
    const length = Math.hypot(x, z) || 1;

    return [x / length, z / length];
}
