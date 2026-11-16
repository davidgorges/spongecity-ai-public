/**
 * The fence line in plan, in the browser: the same layout maths as
 * Core\Configurator\Product\GardenFence\FenceLayout (vertices, closing run,
 * front run, house) and GardenFenceGeometry (gate openings), so the editor's
 * plan can follow every keystroke and drag before the server answers. The
 * server stays the authority: it normalises the value and the plan is drawn
 * again from its answer. Millimetres; plan x/z, z towards the street.
 *
 * `rules` carries the constants of the PHP classes (FenceLayout,
 * GardenFenceGeometry, the system's pitch), passed in by the editor template.
 */

const DEG = Math.PI / 180;

function wrap(degrees) {
    return ((((degrees + 180) % 360) + 360) % 360) - 180;
}

/** Direction of every entered run in degrees; turning inside turns towards -z. */
function headings(runs) {
    let heading = 0;

    return runs.map((run, i) => {
        if (i > 0) {
            heading -= run.turn;
        }

        return heading;
    });
}

function localPoints(runs) {
    const points = [[0, 0]];
    let x = 0;
    let z = 0;
    headings(runs).forEach((heading, i) => {
        x += runs[i].length * Math.cos(heading * DEG);
        z += runs[i].length * Math.sin(heading * DEG);
        points.push([x, z]);
    });

    return points;
}

/** The entered runs and, for a closed line, the run back to the start. */
export function allRuns(layout) {
    const runs = layout.runs;
    if (!layout.closed || runs.length < 2) {
        return runs.map((run) => ({ ...run }));
    }
    const points = localPoints(runs);
    const [lx, lz] = points[points.length - 1];
    const heading = Math.atan2(-lz, -lx) / DEG;
    const last = headings(runs)[runs.length - 1];

    return [
        ...runs.map((run) => ({ ...run })),
        {
            length: Math.round(Math.hypot(lx, lz)),
            turn: Math.round(wrap(last - heading)),
            height: null,
            rise: -runs.reduce((sum, run) => sum + run.rise, 0),
            closing: true,
        },
    ];
}

/** The run that faces the street: the one with the main gate, else the longest. */
export function mainRun(layout) {
    const runs = allRuns(layout);
    if (layout.gates.length > 0 && runs[layout.gates[0].run]) {
        return layout.gates[0].run;
    }

    return runs.reduce((main, run, i) => (run.length > runs[main].length ? i : main), 0);
}

/** Every corner of the line in plan, laid out in front of the house (FenceLayout::vertices()). */
export function vertices(layout, rules) {
    const points = localPoints(layout.runs);
    const count = points.length;
    const main = mainRun(layout);
    const from = points[main];
    const to = points[(main + 1) % count];
    const angle = Math.atan2(to[1] - from[1], to[0] - from[0]);
    const cos = Math.cos(angle);
    const sin = Math.sin(angle);
    const turned = points.map(([x, z]) => [x * cos + z * sin, -x * sin + z * cos]);
    const front = turned[main];
    const frontEnd = turned[(main + 1) % count];
    const minZ = Math.min(...turned.map(([, z]) => z));
    const frontZ = Math.max(rules.minFrontDistance, front[1] - minZ + (layout.closed ? rules.backGap : 0));
    const dx = -(front[0] + frontEnd[0]) / 2;
    const dz = frontZ - front[1];

    return turned.map(([x, z]) => [x + dx, z + dz]);
}

/** Width of a gate type's opening, 0 for an unknown type. */
function gateWidth(type, rules) {
    return rules.gateWidths[type] ?? 0;
}

export function minLengthFor(type, rules) {
    const width = gateWidth(type, rules);

    return width === 0 ? 0 : width + 2 * rules.gatePost + 2 * rules.minBesideGate;
}

/**
 * The gate openings (GardenFenceGeometry::run()): centre, ends and direction.
 *
 * @returns {{run: number, type: string, centre: number[], from: number[], to: number[], direction: number[], width: number}[]}
 */
export function gates(layout, points, rules) {
    const count = points.length;

    return layout.gates.flatMap((gate) => {
        const a = points[gate.run];
        const b = points[(gate.run + 1) % count];
        if (!a || !b) {
            return [];
        }
        const length = Math.hypot(b[0] - a[0], b[1] - a[1]);
        if (length < minLengthFor(gate.type, rules)) {
            return [];
        }
        const width = gateWidth(gate.type, rules);
        const offset = width / 2 + rules.gatePost / 2;
        const centre = { start: rules.pitch + offset, end: length - rules.pitch - offset }[gate.position] ?? length / 2;
        const limit = rules.minBesideGate + offset;
        const d = Math.max(limit, Math.min(length - limit, centre));
        const direction = [(b[0] - a[0]) / length, (b[1] - a[1]) / length];
        const at = (distance) => [a[0] + direction[0] * distance, a[1] + direction[1] * distance];

        return [{ ...gate, centre: at(d), from: at(d - width / 2), to: at(d + width / 2), direction, width }];
    });
}

/** The house's width: its own, or as wide as the front allows (FenceLayout::autoHouseWidth()). */
export function houseWidth(layout, rules) {
    if (layout.house?.width) {
        return layout.house.width;
    }

    return autoHouseWidth(layout, rules);
}

export function autoHouseWidth(layout, rules) {
    const front = allRuns(layout)[mainRun(layout)].length;

    return Math.max(rules.houseMinWidth, Math.min(rules.houseMaxWidth, front - 2 * rules.houseMargin));
}

/** A point of the house's own frame (x along the façade, z towards the front) in plan. */
export function housePoint(house, x, z) {
    const angle = (house.turn ?? 0) * DEG;

    return [house.x + x * Math.cos(angle) + z * Math.sin(angle), house.z - x * Math.sin(angle) + z * Math.cos(angle)];
}

/** Corners of the house in plan: façade left, façade right, back right, back left. */
export function houseCorners(layout, rules) {
    const house = { x: 0, z: 0, turn: 0, ...layout.house };
    const half = houseWidth(layout, rules) / 2;
    const depth = house.depth ?? rules.houseDepth;

    return [housePoint(house, -half, 0), housePoint(house, half, 0), housePoint(house, half, -depth), housePoint(house, -half, -depth)];
}

/**
 * Lengths and corners of the entered runs from corners in plan (the inverse
 * of vertices(), up to where the line lies).
 */
export function runsFromVertices(layout, points, step) {
    let previous = null;

    return layout.runs.map((run, i) => {
        const [x1, z1] = points[i];
        const [x2, z2] = points[i + 1];
        const heading = Math.atan2(z2 - z1, x2 - x1) / DEG;
        const turn = previous === null ? 0 : Math.round(wrap(previous - heading));
        previous = heading;

        return { ...run, length: Math.max(step, Math.round(Math.hypot(x2 - x1, z2 - z1) / step) * step), turn };
    });
}

/** The angle between two runs meeting at a corner, 180° straight on. */
export function cornerAngle(before, at, after) {
    const a = Math.atan2(before[1] - at[1], before[0] - at[0]);
    const b = Math.atan2(after[1] - at[1], after[0] - at[0]);

    return Math.abs(wrap((a - b) / DEG));
}
