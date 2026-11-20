/**
 * Windows snapped together move as one when the group's anchor is dragged (its topmost window,
 * the leftmost of those level): each window's group (null: on its own), kept with its place in the
 * browser. A window joins when it is dropped against another; it leaves when it is dragged while
 * not the anchor (or with Shift), or closed. Pure functions on a map window id → group id
 * (tests/js/window_manager/groups.test.mjs).
 */

/**
 * The windows that move with this one, itself included.
 *
 * @param {Record<string, string|null>} groups
 * @returns {string[]}
 */
export function members(groups, id) {
    const group = groups[id] ?? null;

    return group === null ? [id] : Object.keys(groups).filter((other) => groups[other] === group);
}

/**
 * The group's anchor, which drags the others along: the topmost window, the leftmost of those level.
 *
 * @param {string[]} ids the group's windows
 * @param {Record<string, {x: number, y: number}>} places
 * @returns {string}
 */
export function anchor(ids, places) {
    return [...ids].sort((a, b) => (places[a].y - places[b].y) || (places[a].x - places[b].x))[0];
}

/**
 * The windows, and those they already move with, become one group: the group one of them is in
 * already, else a new one.
 *
 * @param {Record<string, string|null>} groups
 * @param {string[]} ids
 * @param {() => string} newGroup a group id not used yet
 * @returns {Record<string, string|null>}
 */
export function join(groups, ids, newGroup) {
    const all = new Set(ids.flatMap((id) => members(groups, id)));
    if (all.size < 2) {
        return groups;
    }
    const group = ids.map((id) => groups[id] ?? null).find((existing) => existing !== null) ?? newGroup();

    return { ...groups, ...Object.fromEntries([...all].map((id) => [id, group])) };
}

/**
 * The window moves on its own again; a group left with one window is no group.
 *
 * @param {Record<string, string|null>} groups
 * @returns {Record<string, string|null>}
 */
export function leave(groups, id) {
    return normalise({ ...groups, [id]: null });
}

/**
 * Groups of one window are none.
 *
 * @param {Record<string, string|null>} groups
 * @returns {Record<string, string|null>}
 */
export function normalise(groups) {
    const counts = {};
    Object.values(groups).forEach((group) => {
        if (group !== null && group !== undefined) {
            counts[group] = (counts[group] ?? 0) + 1;
        }
    });

    return Object.fromEntries(Object.entries(groups).map(([id, group]) => [id, (counts[group] ?? 0) > 1 ? group : null]));
}
