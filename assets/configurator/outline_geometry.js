import * as THREE from 'three';

/**
 * Geometry of outlines in plan ([[x, z], …] metres, x east, z south, seen from above with y up):
 * shared by the views that build on an intervention's outline (rain gardens, permeable surfaces).
 */

/** counter-clockwise seen from above (y up, x east, z south): the shoelace over [x, z] is negative */
export function counterClockwise(points) {
    return polygonSignedArea(points) > 0 ? [...points].reverse() : points;
}

export function polygonSignedArea(points) {
    let sum = 0;
    points.forEach(([x1, z1], i) => {
        const [x2, z2] = points[(i + 1) % points.length];
        sum += x1 * z2 - x2 * z1;
    });

    return sum / 2;
}

export function polygonArea(points) {
    return Math.abs(polygonSignedArea(points));
}

/** the direction into the outline at a corner: the mean of its two edges' inward normals */
export function inwardNormal(points, i) {
    const count = points.length;
    const [px, pz] = points[(i - 1 + count) % count];
    const [x, z] = points[i];
    const [nx, nz] = points[(i + 1) % count];
    const edge = (ax, az, bx, bz) => {
        const length = Math.hypot(bx - ax, bz - az) || 1;

        return [(bz - az) / length, -(bx - ax) / length];
    };
    const [ax, az] = edge(px, pz, x, z);
    const [bx, bz] = edge(x, z, nx, nz);
    let [mx, mz] = [ax + bx, az + bz];
    const length = Math.hypot(mx, mz) || 1;
    [mx, mz] = [mx / length, mz / length];
    // try a little step: inside, it is the inward normal; outside, the other way
    return contains(points, x + mx * 0.01, z + mz * 0.01) ? [mx, mz] : [-mx, -mz];
}

/**
 * How far it is from a corner straight across the outline (along its inward normal) to the other
 * side: the width of the basin there. A smoothed outline has corners every few decimetres, so the
 * nearest other edge says nothing; the far side does.
 */
export function acrossFrom(points, i, [nx, nz], fallback) {
    const count = points.length;
    const [x, z] = points[i];
    let nearest = Infinity;
    for (let e = 0; e < count; e++) {
        if (e === i || (e + 1) % count === i) {
            continue;
        }
        const [ax, az] = points[e];
        const [bx, bz] = points[(e + 1) % count];
        // the ray x + t·n against the edge a + s·(b − a)
        const [ex, ez] = [bx - ax, bz - az];
        const denominator = nx * ez - nz * ex;
        if (Math.abs(denominator) < 1e-9) {
            continue;
        }
        const t = ((ax - x) * ez - (az - z) * ex) / denominator;
        const along = ((ax - x) * nz - (az - z) * nx) / denominator;
        if (t > 0.01 && along >= 0 && along <= 1) {
            nearest = Math.min(nearest, t);
        }
    }

    return Number.isFinite(nearest) ? nearest : fallback;
}

export function edgeDistance(points, x, z) {
    let nearest = Infinity;
    points.forEach((point, i) => {
        nearest = Math.min(nearest, segmentDistance(x, z, point, points[(i + 1) % points.length]));
    });

    return nearest;
}

export function segmentDistance(x, z, [ax, az], [bx, bz]) {
    const [dx, dz] = [bx - ax, bz - az];
    const length = dx * dx + dz * dz;
    const t = length > 0 ? Math.max(0, Math.min(1, ((x - ax) * dx + (z - az) * dz) / length)) : 0;

    return Math.hypot(x - (ax + t * dx), z - (az + t * dz));
}

export function contains(points, x, z) {
    let inside = false;
    points.forEach(([ax, az], i) => {
        const [bx, bz] = points[(i + 1) % points.length];
        if ((az > z) !== (bz > z) && x < ax + ((bx - ax) * (z - az)) / (bz - az)) {
            inside = !inside;
        }
    });

    return inside;
}

export function bounds(points) {
    const xs = points.map(([x]) => x);
    const zs = points.map(([, z]) => z);

    return [Math.min(...xs), Math.max(...xs), Math.min(...zs), Math.max(...zs)];
}

/** triangles over a ring of [x, y, z] points (earcut on x/z), counter-clockwise from above */
export function triangulate(ring) {
    const contour = ring.map(([x, , z]) => new THREE.Vector2(x, -z));
    const triangles = THREE.ShapeUtils.triangulateShape(contour, []);
    const clockwise = THREE.ShapeUtils.isClockWise(contour);

    return triangles.map(([a, b, c]) => (clockwise ? [a, c, b] : [a, b, c]));
}

export function seededRandom(seed) {
    return () => {
        seed |= 0;
        seed = (seed + 0x6d2b79f5) | 0;
        let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;

        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}
