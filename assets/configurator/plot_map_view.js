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
/** the stripes of permeable paving, CSS pixels apart */
const HATCH_PIXELS = 4;
/** a tree's trunk on the map, CSS pixels */
const TRUNK_PIXELS = 1.5;

/** the site object types the map draws (SiteObject) */
export const RAINGARDEN = 'raingarden';
export const PLANTING = 'planting';
export const PERMEABLE = 'permeable';
export const SHADESAIL = 'shadesail';
export const NEWTREE = 'newtree';
/** façade greening (SiteObject::FACADEGREEN): a line along a wall */
export const FACADEGREEN = 'facadegreen';
/** a light coating on sealed ground (SiteObject::COOLSURFACE) */
export const COOLSURFACE = 'coolsurface';
/** a green roof (SiteObject::GREENROOF): its points are the building's footprint, drawn over the building */
export const GREENROOF = 'greenroof';
export const EXISTING_TREE = 'tree';
/** play equipment (SiteObject::EQUIPMENT): its fall zone, which nothing planned may take */
export const EQUIPMENT = 'equipment';
/** the equipment's own spot in its fall zone, CSS pixels */
const EQUIPMENT_DOT_PIXELS = 3;
/** where a zone's use comes from (Zone::ASSUMED): drawn dashed, it is a guess */
export const ZONE_ASSUMED = 'assumed';

/** zones by use (SiteObject::AREA_USES): a light wash under everything planned */
export const ZONE_STYLES = {
    yard: { fill: '#cbbf9f', stroke: '#8f8264' },
    parking: { fill: '#8ea3b9', stroke: '#566d86' },
    access: { fill: '#d98585', stroke: '#a24848' },
    sport: { fill: '#e7a15c', stroke: '#b26a22' },
    play: { fill: '#ebc94d', stroke: '#a88713' },
    quiet: { fill: '#b6a5de', stroke: '#7562a8' },
    green: { fill: '#8cc477', stroke: '#4f8a3a' },
};
const ZONE_FILL_OPACITY = 0.28;
const ZONE_SELECTED_FILL_OPACITY = 0.5;

/** the ground first, then what stands above it */
const SURFACES = [COOLSURFACE, PERMEABLE, PLANTING, RAINGARDEN];
const ABOVE = [GREENROOF, FACADEGREEN, SHADESAIL, NEWTREE];

/**
 * How each kind of object looks on the map and in its legend: map colours, the same in the
 * light and the dark theme (like a printed plan), close to how the 3D view shows them.
 */
export const STYLES = {
    [RAINGARDEN]: { fill: '#3f8fa8', fillOpacity: 0.6, stroke: '#1f6378' },
    [PLANTING]: { fill: '#6c9b46', fillOpacity: 0.55, stroke: '#46702a' },
    [PERMEABLE]: { fill: '#c2b49a', fillOpacity: 0.7, stroke: '#8d7d60', hatch: true },
    [SHADESAIL]: { fill: '#e8c872', fillOpacity: 0.35, stroke: '#b98a1e', dashed: true },
    [GREENROOF]: { fill: '#7d9a4a', fillOpacity: 0.55, stroke: '#4d6a26', dashed: true },
    [NEWTREE]: { fill: '#3f8a3a', fillOpacity: 0.5, stroke: '#24611f' },
    [FACADEGREEN]: { fill: '#4f7f35', fillOpacity: 0.9, stroke: '#2f5a1c' },
    [COOLSURFACE]: { fill: '#e6e3da', fillOpacity: 0.7, stroke: '#9c988c' },
    [EXISTING_TREE]: { fill: '#6f9a5a', fillOpacity: 0.18, stroke: '#6f9a5a', strokeOpacity: 0.5 },
    [EQUIPMENT]: { fill: '#ebc94d', fillOpacity: 0.3, stroke: '#a88713', dashed: true },
};

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
    /** what is drawn around and on the plot, unchanged while a corner is dragged */
    #trees = [];
    #interventions = [];
    #zones = [];
    #selectedZone = null;
    #equipment = [];
    #selectedEquipment = null;

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

    /**
     * @param {{x: number, z: number, r: number}[]} trees the existing trees' crowns
     * @param {{id: string, type: string, label: ?string, points?: number[][], x?: number, z?: number, r?: number}[]} interventions
     * @param {{id: string, use: string, source: string, label: string, points: number[][]}[]} zones the plot's zones (SurfaceZones)
     * @param {?string} selectedZone
     * @param {{id: string, kind: string, label: ?string, x: number, z: number, points: number[][]}[]} equipment play equipment with its fall zone
     * @param {?string} selectedEquipment
     */
    setContext(trees, interventions, zones = [], selectedZone = null, equipment = [], selectedEquipment = null) {
        this.#trees = trees;
        this.#interventions = interventions;
        this.#zones = zones;
        this.#selectedZone = selectedZone;
        this.#equipment = equipment;
        this.#selectedEquipment = selectedEquipment;
    }

    /** the name a person reads for an intervention: its label, else its kind */
    name(intervention) {
        return intervention.label || this.#texts.types?.[intervention.type] || intervention.type;
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

        // the analysed area's tint lies at the bottom: the zones and everything planned show through it
        this.#svg.append(el('polygon', {
            points: polygonPoints(points),
            class: invalid ? 'pointer-events-none fill-destructive/10' : `pointer-events-none ${this.#zones.length > 0 ? 'fill-primary/5' : 'fill-primary/15'}`,
            stroke: 'none',
        }));

        // the zones over it, each a target: a click or Enter selects it to set its use
        this.#zones.forEach((zone) => this.#svg.append(this.#zone(zone)));

        const hatch = `${this.#id}-hatch`;
        defs.append(this.#hatch(hatch, HATCH_PIXELS / pixels));
        const trunk = TRUNK_PIXELS / pixels;
        this.#trees.forEach((tree) => this.#svg.append(this.#tree(tree, STYLES[EXISTING_TREE], trunk, this.#texts.existingTree)));
        this.#interventions
            .filter((item) => SURFACES.includes(item.type))
            .sort((a, b) => SURFACES.indexOf(a.type) - SURFACES.indexOf(b.type))
            .forEach((item) => this.#svg.append(this.#surface(item, hatch)));
        // play equipment over the ground it takes, each a target to say what it is
        this.#equipment.forEach((item) => this.#svg.append(this.#equipmentItem(item, EQUIPMENT_DOT_PIXELS / pixels)));

        // only its line above them, with the handles to reshape it
        this.#svg.append(el('polygon', {
            points: polygonPoints(points),
            fill: 'none',
            class: invalid ? 'pointer-events-none stroke-destructive' : 'pointer-events-none stroke-primary',
            'stroke-width': 2,
            'stroke-linejoin': 'round',
            'vector-effect': 'non-scaling-stroke',
            'data-plot-map-target': 'outline',
        }));

        // green roofs, sails and new trees stand above the ground: over the plot's outline, under its handles
        this.#interventions
            .filter((item) => ABOVE.includes(item.type))
            .forEach((item) => this.#svg.append(item.type === NEWTREE ? this.#tree(item, STYLES[NEWTREE], trunk, this.name(item)) : this.#surface(item, hatch)));

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

    #zone(zone) {
        const style = ZONE_STYLES[zone.use] ?? ZONE_STYLES.yard;
        const selected = zone.id === this.#selectedZone;
        const name = this.#texts.zone?.replace('%name%', zone.label) ?? zone.label;

        return el('polygon', {
            points: polygonPoints(zone.points),
            fill: style.fill,
            'fill-opacity': selected ? ZONE_SELECTED_FILL_OPACITY : ZONE_FILL_OPACITY,
            stroke: selected ? 'currentColor' : style.stroke,
            'stroke-width': selected ? 2.5 : 1,
            'stroke-linejoin': 'round',
            'vector-effect': 'non-scaling-stroke',
            ...(zone.source === ZONE_ASSUMED && !selected ? { 'stroke-dasharray': '3 3' } : {}),
            class: `cursor-pointer outline-none transition-[fill-opacity] hover:[fill-opacity:0.5] focus-visible:[fill-opacity:0.5] ${selected ? 'text-foreground' : ''}`,
            tabindex: 0,
            role: 'button',
            'aria-label': name,
            'aria-pressed': String(selected),
            'data-zone': zone.id,
            'data-action': `click->${CONTROLLER}#selectZone keydown->${CONTROLLER}#selectZoneByKey`,
            [`data-${CONTROLLER}-zone-param`]: zone.id,
        }, null, name);
    }

    /** Play equipment: its fall zone, dashed, and a dot where it stands. */
    #equipmentItem(item, dot) {
        const style = STYLES[EQUIPMENT];
        const selected = item.id === this.#selectedEquipment;
        const kind = this.#texts.kinds?.[item.kind] ?? item.kind;
        const name = this.#texts.equipment?.replace('%name%', item.label || kind) ?? kind;
        const group = el('g', {
            class: `cursor-pointer outline-none [&:hover>polygon]:[fill-opacity:0.55] [&:focus-visible>polygon]:[fill-opacity:0.55] ${selected ? 'text-foreground' : ''}`,
            tabindex: 0,
            role: 'button',
            'aria-label': name,
            'aria-pressed': String(selected),
            'data-type': EQUIPMENT,
            'data-id': item.id,
            'data-action': `click->${CONTROLLER}#selectEquipment keydown->${CONTROLLER}#selectEquipmentByKey`,
            [`data-${CONTROLLER}-equipment-param`]: item.id,
        });
        group.append(
            el('polygon', {
                points: polygonPoints(item.points),
                fill: style.fill,
                'fill-opacity': selected ? 0.55 : style.fillOpacity,
                stroke: selected ? 'currentColor' : style.stroke,
                'stroke-width': selected ? 2.5 : 1.2,
                'stroke-dasharray': selected ? 'none' : '3 2',
                'stroke-linejoin': 'round',
                'vector-effect': 'non-scaling-stroke',
                class: 'transition-[fill-opacity]',
            }, null, name),
            el('circle', { cx: item.x, cy: item.z, r: dot, fill: style.stroke, class: 'pointer-events-none' }),
        );

        return group;
    }

    /** An outline intervention, with its name for a pointer resting on it. */
    #surface(item, hatch) {
        const style = STYLES[item.type];
        // a click goes through to the zone under it: what it is used for is what the map edits
        const group = el('g', { 'data-type': item.type, 'data-id': item.id, class: 'pointer-events-none' });
        const attributes = {
            points: polygonPoints(item.points ?? []),
            fill: style.fill,
            'fill-opacity': style.fillOpacity,
            stroke: style.stroke,
            'stroke-width': 1.5,
            'stroke-linejoin': 'round',
            'vector-effect': 'non-scaling-stroke',
            ...(style.dashed ? { 'stroke-dasharray': '4 3' } : {}),
        };
        group.append(el('polygon', attributes, null, this.name(item)));
        if (style.hatch) {
            group.append(el('polygon', { points: attributes.points, fill: `url(#${hatch})`, class: 'pointer-events-none' }));
        }

        return group;
    }

    /** A tree: its crown, and its trunk as a dot. */
    #tree({ id, x, z, r }, style, trunk, name) {
        const group = el('g', { 'data-type': style === STYLES[NEWTREE] ? NEWTREE : EXISTING_TREE, ...(id ? { 'data-id': id } : {}), class: 'pointer-events-none' });
        group.append(
            el('circle', { cx: x, cy: z, r: Math.max(r, trunk * 2), fill: style.fill, 'fill-opacity': style.fillOpacity, stroke: style.stroke, 'stroke-opacity': style.strokeOpacity ?? 1, 'stroke-width': 1, 'vector-effect': 'non-scaling-stroke' }, null, name),
            el('circle', { cx: x, cy: z, r: trunk, fill: style.stroke, class: 'pointer-events-none' }),
        );

        return group;
    }

    /** Stripes for permeable paving, a few pixels apart whatever the zoom. */
    #hatch(id, spacing) {
        const pattern = el('pattern', { id, width: spacing, height: spacing, patternUnits: 'userSpaceOnUse', patternTransform: 'rotate(45)' });
        pattern.append(el('path', { d: `M0 0V${spacing}`, stroke: STYLES[PERMEABLE].stroke, 'stroke-opacity': 0.55, 'stroke-width': 1, 'vector-effect': 'non-scaling-stroke' }));

        return pattern;
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

/** An SVG element; with a title, a pointer resting on it names it (and assistive technology reads it). */
function el(name, attributes = {}, content = null, title = null) {
    const node = document.createElementNS(SVG, name);
    for (const [key, value] of Object.entries(attributes)) {
        node.setAttribute(key, String(value));
    }
    if (content !== null) {
        node.textContent = content;
    }
    if (title !== null) {
        node.append(el('title', {}, title));
    }

    return node;
}

/**
 * The legend of what the map shows besides the plot: the zones' uses, then the kinds of objects on it.
 *
 * @param {HTMLElement} list a <ul>
 * @param {string[]} uses the zones' uses on the map
 * @param {string[]} types the kinds of objects on the map
 * @param {(key: string) => string} name
 */
export function renderLegend(list, uses, types, name) {
    const zoneEntries = Object.keys(ZONE_STYLES).filter((use) => uses.includes(use))
        .map((use) => ({ key: use, style: { ...ZONE_STYLES[use], fillOpacity: ZONE_SELECTED_FILL_OPACITY }, round: false }));
    const objectEntries = Object.keys(STYLES).filter((type) => types.includes(type))
        .map((type) => ({ key: type, style: STYLES[type], round: type === NEWTREE || type === EXISTING_TREE }));
    list.replaceChildren(...[...zoneEntries, ...objectEntries].map(({ key, style, round }) => {
        const item = document.createElement('li');
        item.className = 'flex items-center gap-1.5';
        const swatch = document.createElementNS(SVG, 'svg');
        swatch.setAttribute('viewBox', '0 0 12 12');
        swatch.setAttribute('class', 'size-3 shrink-0');
        swatch.setAttribute('aria-hidden', 'true');
        swatch.append(el(round ? 'circle' : 'rect', {
            ...(round ? { cx: 6, cy: 6, r: 5 } : { x: 1, y: 1, width: 10, height: 10, rx: 1.5 }),
            fill: style.fill,
            'fill-opacity': Math.max(style.fillOpacity, 0.35),
            stroke: style.stroke,
            'stroke-width': 1.2,
            ...(style.dashed ? { 'stroke-dasharray': '2 1.5' } : {}),
        }));
        item.append(swatch, document.createTextNode(name(key)));

        return item;
    }));
}

function text(content, attributes) {
    return el('text', attributes, content);
}
