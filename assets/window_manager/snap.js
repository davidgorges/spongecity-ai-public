/**
 * Where a window being moved or resized snaps to: the edges of the other windows (beside them a
 * gap apart, or lined up with them) and the edges of the view, else an invisible grid. Pure
 * geometry on rectangles {x, y, w, h} in CSS pixels, so it is tested without a page
 * (tests/js/window_manager/snap.test.mjs).
 */

/**
 * @typedef {{x: number, y: number, w: number, h: number}} Rect
 * @typedef {{left: number, top: number, right: number, bottom: number}} Bounds the view's edges windows snap to
 * @typedef {{threshold: number, gap: number}} SnapOptions threshold: how near an edge snaps; gap: kept between windows side by side
 * @typedef {{delta: number|null, guide: number|null}} AxisSnap delta: the correction on that axis (null: nothing near); guide: where the edge snapped to
 */

/** the axes, and the names of a rectangle's position and size along each (then across it) */
const X = 'x';
const Y = 'y';
const AXES = [X, Y];
const NAMES = { [X]: ['x', 'w', 'y', 'h'], [Y]: ['y', 'h', 'x', 'w'] };

/** the nearest multiple of grid */
export function toGrid(value, grid) {
    return Math.round(value / grid) * grid;
}

/** whether two spans [a1, a2] and [b1, b2], each widened by slack, overlap */
function spansMeet(a1, a2, b1, b2, slack) {
    return a1 - slack < b2 && b1 - slack < a2;
}

/**
 * The smallest correction that brings one of the edges onto one of the lines, if it is at most
 * threshold away.
 *
 * @param {Array<[number, number[]]>} pairs each edge with the lines it may snap to
 * @returns {AxisSnap}
 */
function nearest(pairs, threshold) {
    let best = { delta: null, guide: null };
    for (const [edge, lines] of pairs) {
        for (const line of lines) {
            const delta = line - edge;
            if (Math.abs(delta) <= threshold && (best.delta === null || Math.abs(delta) < Math.abs(best.delta))) {
                best = { delta, guide: line };
            }
        }
    }

    return best;
}

/**
 * The lines a window's vertical edges (axis x) or horizontal edges (axis y) may snap to: beside
 * each other window near it on the other axis, lined up with it, and the view's edges.
 *
 * @param {Rect} rect
 * @param {Rect[]} targets
 * @param {Bounds} bounds
 * @param {SnapOptions} options
 * @returns {{start: number[], end: number[]}} lines for the edge at the start (left, top) and at the end (right, bottom)
 */
function lines(rect, targets, bounds, axis, { threshold, gap }) {
    const [pos, size, otherPos, otherSize] = NAMES[axis];
    const start = [axis === X ? bounds.left : bounds.top];
    const end = [axis === X ? bounds.right : bounds.bottom];
    for (const target of targets) {
        if (!spansMeet(rect[otherPos], rect[otherPos] + rect[otherSize], target[otherPos], target[otherPos] + target[otherSize], gap + threshold)) {
            continue;
        }
        const targetStart = target[pos];
        const targetEnd = target[pos] + target[size];
        // beside it, a gap apart, or lined up with it
        start.push(targetEnd + gap, targetStart);
        end.push(targetStart - gap, targetEnd);
    }

    return { start, end };
}

/**
 * The correction that snaps windows moved together (a group) onto the others' edges or the view's:
 * the nearest edge of any of them wins, on each axis apart.
 *
 * @param {Rect[]} moving where the windows would be, unsnapped
 * @param {Rect[]} targets the other windows
 * @param {Bounds} bounds
 * @param {SnapOptions} options
 * @returns {{x: AxisSnap, y: AxisSnap}}
 */
export function snapMove(moving, targets, bounds, options) {
    const pairs = { x: [], y: [] };
    for (const rect of moving) {
        for (const axis of AXES) {
            const { start, end } = lines(rect, targets, bounds, axis, options);
            const [pos, size] = NAMES[axis];
            pairs[axis].push([rect[pos], start], [rect[pos] + rect[size], end]);
        }
    }

    return { x: nearest(pairs.x, options.threshold), y: nearest(pairs.y, options.threshold) };
}

/**
 * The correction that snaps the right and bottom edges of a window resized by its corner onto the
 * others' edges or the view's.
 *
 * @param {Rect} rect the window as it would be, unsnapped
 * @param {Rect[]} targets the other windows
 * @param {Bounds} bounds
 * @param {SnapOptions} options
 * @returns {{x: AxisSnap, y: AxisSnap}} x corrects the width, y the height
 */
export function snapResize(rect, targets, bounds, options) {
    return {
        x: nearest([[rect.x + rect.w, lines(rect, targets, bounds, X, options).end]], options.threshold),
        y: nearest([[rect.y + rect.h, lines(rect, targets, bounds, Y, options).end]], options.threshold),
    };
}

/**
 * Whether two windows sit side by side, gap apart (within tolerance), and overlap along that side:
 * snapped together.
 *
 * @param {Rect} a
 * @param {Rect} b
 */
export function touching(a, b, gap, tolerance) {
    const near = (from, to) => Math.abs(from + gap - to) <= tolerance;
    const overlapX = Math.min(a.x + a.w, b.x + b.w) > Math.max(a.x, b.x);
    const overlapY = Math.min(a.y + a.h, b.y + b.h) > Math.max(a.y, b.y);

    return (overlapY && (near(a.x + a.w, b.x) || near(b.x + b.w, a.x)))
        || (overlapX && (near(a.y + a.h, b.y) || near(b.y + b.h, a.y)));
}

/**
 * Whether window b hangs under window a: its top edge gap below a's bottom edge (within
 * tolerance), the two overlapping across.
 *
 * @param {Rect} a
 * @param {Rect} b
 */
export function hangsUnder(a, b, gap, tolerance) {
    return Math.abs(a.y + a.h + gap - b.y) <= tolerance && Math.min(a.x + a.w, b.x + b.w) > Math.max(a.x, b.x);
}
