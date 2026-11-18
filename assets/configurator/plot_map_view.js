const SVG = 'http://www.w3.org/2000/svg';
const CONTROLLER = 'plot-map';
/** nice lengths for the scale bar, millimetres */
const SCALES = [5000, 10000, 20000, 50000, 100000];
/** grid spacing, millimetres: the finer one while the frame is small enough for it */
const GRID_FINE = 5000;
const GRID_COARSE = 10000;
const GRID_FINE_UP_TO = 80000;
/** room around the plot in the frame: a share of its size, at least this many millimetres (the buildings next to it show) */
const FRAME_SHARE = 0.3;
const FRAME_MIN_MARGIN = 15000;
/** the on-screen font of the map's text, CSS pixels */
const FONT_SHARE = 0.026;
const FONT_MIN = 10;
const FONT_MAX = 13;
/** the smallest a touch target may be, CSS pixels: WCAG 2.5.8 with a mouse, a fingertip on touch screens */
const MIN_TARGET = 24;
const MIN_TOUCH_TARGET = 44;
/** any finger on the device (a touch-screen laptop's primary pointer is the trackpad) */
const COARSE_POINTER = '(any-pointer: coarse)';
/** an edge shorter than this on screen (CSS pixels) gets no handle to add a corner: it would sit on the corners */
const MIN_EDGE_FOR_INSERT = 40;

/**
 * Draws the plot (the analysed area) in plan, north up, into an SVG: a grid,
 * the buildings around it, the plot's outline with a handle on every corner and
 * one in the middle of every edge (to add a corner there), a scale bar and a
 * north arrow. Interaction stays with the plot-map controller: the elements
 * carry its data-action attributes and params.
 */
export class PlotMapView {
    #svg;
    #texts;
    #format;
    #viewBox = null;
    /** read once: every drag frame redraws the map */
    #coarse = matchMedia(COARSE_POINTER);
    #id;

    /**
     * @param {SVGSVGElement} svg
     * @param {object} texts translated labels: corner (%number%), insert (%number%), north
     */
    constructor(svg, texts) {
        this.#svg = svg;
        this.#texts = texts;
        this.#id = svg.id || 'plot-map';
        this.#format = new Intl.NumberFormat(document.documentElement.lang || undefined, { maximumFractionDigits: 1 });
    }

    metres(millimetres) {
        return `${this.#format.format(millimetres / 1000)} m`;
    }

    squareMetres(squareMillimetres) {
        return `${new Intl.NumberFormat(document.documentElement.lang || undefined, { maximumFractionDigits: 0 }).format(squareMillimetres / 1e6)} m²`;
    }

    /**
     * @param {number[][]} points the plot's corners [x, z]
     * @param {number[][][]} buildings the buildings' footprints
     * @param {{keepView?: boolean, invalid?: boolean, active?: number|null}} options
     *        keepView: keep the frame (while dragging, so the map does not jump);
     *        invalid: the outline crosses itself (drawn as an error);
     *        active: the corner being dragged
     */
    render(points, buildings, { keepView = false, invalid = false, active = null } = {}) {
        if (!keepView || !this.#viewBox) {
            this.#viewBox = this.#frame(points);
        }
        const [minX, minZ, width, height] = this.#viewBox;
        const extent = Math.max(width, height);
        const pixels = this.#svg.clientWidth > 0 ? this.#svg.clientWidth / width : 0.01;
        const onScreen = (share, min, max) => Math.min(max, Math.max(min, extent * share * pixels)) / pixels;
        const font = onScreen(FONT_SHARE, FONT_MIN, FONT_MAX);
        const handle = onScreen(0.016, 6, 9);
        const target = (this.#coarse.matches ? MIN_TOUCH_TARGET : MIN_TARGET) / 2 / pixels;
        const hit = Math.max(handle * 1.6, target);
        const count = points.length;

        this.#svg.setAttribute('viewBox', `${minX} ${minZ} ${width} ${height}`);
        this.#svg.setAttribute('overflow', 'hidden');
        this.#svg.replaceChildren();

        const grid = extent <= GRID_FINE_UP_TO ? GRID_FINE : GRID_COARSE;
        const defs = el('defs');
        const pattern = el('pattern', { id: `${this.#id}-grid`, width: grid, height: grid, patternUnits: 'userSpaceOnUse', x: 0, y: 0 });
        pattern.append(el('path', { d: `M${grid} 0H0V${grid}`, fill: 'none', class: 'stroke-border', 'stroke-width': 1, 'vector-effect': 'non-scaling-stroke' }));
        defs.append(pattern);
        this.#svg.append(defs, el('rect', { x: minX, y: minZ, width, height, fill: `url(#${this.#id}-grid)`, class: 'pointer-events-none' }));

        buildings.forEach((outline) => this.#svg.append(el('polygon', {
            points: polygonPoints(outline),
            class: 'pointer-events-none fill-muted-foreground/25 stroke-muted-foreground/60',
            'stroke-width': 1,
            'vector-effect': 'non-scaling-stroke',
        })));

        this.#svg.append(el('polygon', {
            points: polygonPoints(points),
            class: invalid ? 'pointer-events-none fill-destructive/10 stroke-destructive' : 'pointer-events-none fill-primary/15 stroke-primary',
            'stroke-width': 2.5,
            'stroke-linejoin': 'round',
            'vector-effect': 'non-scaling-stroke',
            'data-plot-map-target': 'outline',
        }));

        // a corner can be added in the middle of every edge long enough on screen (not while one is dragged)
        if (active === null) {
            points.forEach(([x1, z1], i) => {
                const [x2, z2] = points[(i + 1) % count];
                if (Math.hypot(x2 - x1, z2 - z1) * pixels < MIN_EDGE_FOR_INSERT) {
                    return;
                }
                const group = el('g', {
                    transform: `translate(${(x1 + x2) / 2} ${(z1 + z2) / 2})`,
                    class: 'group/insert cursor-copy touch-none outline-none',
                    tabindex: 0,
                    role: 'button',
                    'aria-label': this.#texts.insert.replace('%number%', i + 1).replace('%next%', (i + 1) % count + 1),
                    'data-action': `touchstart->${CONTROLLER}#holdTouch:!passive pointerdown->${CONTROLLER}#grabEdge keydown->${CONTROLLER}#insertByKey`,
                    [`data-${CONTROLLER}-edge-param`]: i,
                });
                group.append(
                    el('circle', { r: hit, class: 'fill-transparent' }),
                    el('circle', { r: handle * 0.75, class: 'fill-background/80 stroke-primary/60 transition-colors group-hover/insert:fill-accent group-hover/insert:stroke-primary group-focus-visible/insert:fill-primary', 'stroke-width': 1.5, 'stroke-dasharray': '2 2', 'vector-effect': 'non-scaling-stroke' }),
                    el('path', { d: `M${-handle * 0.4} 0H${handle * 0.4}M0 ${-handle * 0.4}V${handle * 0.4}`, class: 'stroke-primary group-focus-visible/insert:stroke-primary-foreground', 'stroke-width': 1.5, 'vector-effect': 'non-scaling-stroke' }),
                );
                this.#svg.append(group);
            });
        }

        points.forEach(([x, z], i) => {
            const group = el('g', {
                transform: `translate(${x} ${z})`,
                // touch-none: a finger on a corner drags it (the rest of the map lets the page scroll)
                class: 'group/handle cursor-grab touch-none outline-none active:cursor-grabbing',
                tabindex: 0,
                role: 'button',
                'aria-label': this.#texts.corner.replace('%number%', i + 1),
                'data-plot-map-target': 'handle',
                'data-action': `touchstart->${CONTROLLER}#holdTouch:!passive pointerdown->${CONTROLLER}#grab dblclick->${CONTROLLER}#removeCorner keydown->${CONTROLLER}#nudge`,
                [`data-${CONTROLLER}-corner-param`]: i,
                ...(active === i ? { 'data-active': '' } : {}),
            });
            group.append(
                el('circle', { r: hit, class: 'fill-transparent' }),
                el('circle', { r: handle, class: 'fill-background stroke-primary transition-colors group-hover/handle:fill-accent group-focus-visible/handle:fill-primary group-data-active/handle:fill-primary', 'stroke-width': 2, 'vector-effect': 'non-scaling-stroke' }),
            );
            this.#svg.append(group);
        });

        this.#svg.append(this.#north(minX, minZ, font), this.#scaleBar(minX, minZ, width, height, font), this.#tip(font));
    }

    /** The plot in the middle with room around it, in the shape of the box (4:3 by default). */
    #frame(points) {
        const xs = points.map(([x]) => x);
        const zs = points.map(([, z]) => z);
        const minX = Math.min(...xs);
        const maxX = Math.max(...xs);
        const minZ = Math.min(...zs);
        const maxZ = Math.max(...zs);
        const margin = Math.max(Math.max(maxX - minX, maxZ - minZ) * FRAME_SHARE, FRAME_MIN_MARGIN);
        const aspect = this.#svg.clientWidth > 0 && this.#svg.clientHeight > 0 ? this.#svg.clientWidth / this.#svg.clientHeight : 4 / 3;
        let width = maxX - minX + 2 * margin;
        let height = maxZ - minZ + 2 * margin;
        if (width / height < aspect) {
            width = height * aspect;
        } else {
            height = width / aspect;
        }

        return [(minX + maxX) / 2 - width / 2, (minZ + maxZ) / 2 - height / 2, width, height].map(Math.round);
    }

    #north(minX, minZ, font) {
        const x = minX + font * 1.4;
        const y = minZ + font * 1.2;
        const group = el('g', { class: 'pointer-events-none', role: 'img', 'aria-label': this.#texts.north });
        group.append(
            el('path', { d: `M${x} ${y}l${font * 0.45} ${font * 1.3}l${-font * 0.45} ${-font * 0.35}l${-font * 0.45} ${font * 0.35}z`, class: 'fill-muted-foreground' }),
            text('N', { x, y: y + font * 2.3, 'font-size': font * 0.85, 'text-anchor': 'middle', class: 'fill-muted-foreground font-medium' }),
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
        const group = el('g', { 'data-plot-map-target': 'tip', class: 'pointer-events-none', visibility: 'hidden' });
        group.append(
            el('rect', { height: font * 1.7, rx: font * 0.4, class: 'fill-foreground' }),
            text('', { 'font-size': font, 'dominant-baseline': 'central', class: 'fill-background tabular-nums' }),
        );
        group.dataset.font = String(font);

        return group;
    }
}

/** The area of a polygon, square millimetres (shoelace). */
export function area(points) {
    let sum = 0;
    points.forEach(([x1, z1], i) => {
        const [x2, z2] = points[(i + 1) % points.length];
        sum += x1 * z2 - x2 * z1;
    });

    return Math.abs(sum) / 2;
}

/** Whether two edges of the closed outline cross (neighbours share a corner, that is no crossing). */
export function crossesItself(points) {
    const count = points.length;
    for (let i = 0; i < count; ++i) {
        const a = points[i];
        const b = points[(i + 1) % count];
        for (let j = i + 2; j < count; ++j) {
            if (i === 0 && j === count - 1) {
                continue;
            }
            if (segmentsCross(a, b, points[j], points[(j + 1) % count])) {
                return true;
            }
        }
    }

    return false;
}

function segmentsCross([ax, az], [bx, bz], [cx, cz], [dx, dz]) {
    const side = (px, pz, qx, qz, rx, rz) => Math.sign((qx - px) * (rz - pz) - (qz - pz) * (rx - px));

    return side(ax, az, bx, bz, cx, cz) * side(ax, az, bx, bz, dx, dz) < 0
        && side(cx, cz, dx, dz, ax, az) * side(cx, cz, dx, dz, bx, bz) < 0;
}

function polygonPoints(points) {
    return points.map(([x, z]) => `${x},${z}`).join(' ');
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
