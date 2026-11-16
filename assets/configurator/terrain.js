import * as THREE from 'three';

/**
 * The ground of a real site (the plan's terrain endpoint, App\SpongeCity\Terrain\Terrain):
 * heights in metres relative to the plan's origin, from a fine grid over the site and a
 * coarse one over the surroundings, fading to 0 between fadeInner and fadeOuter from the
 * centre (the generic city beyond is flat).
 *
 * Within a grid the ground is linear on the two triangles of each cell (split from the
 * north-west to the south-east node), as TerrainGrid::heightAt computes it on the server,
 * where upright objects are stood on it. The lawn is built on exactly these triangles
 * (fineTriangles), and a draped ground layer is cut along the grid lines and diagonals so
 * every piece lies within one of them: lawn, plot, roads and surfaces then run parallel
 * at their millimetre offsets on any slope, instead of cutting through each other.
 */
export class TerrainField {
    /** no terrain: everything flat at 0, as before */
    static FLAT = new TerrainField(null);
    /** outside the fine grid (the real city's streets): plain subdivision to this edge, metres */
    static DRAPE_EDGE = 2;

    #grids = [];
    #fadeInner = Infinity;
    #fadeOuter = Infinity;

    /** @param {{fadeInner: number, fadeOuter: number, grids: object[]}|null} data */
    constructor(data) {
        if (!data?.grids?.length) {
            return;
        }
        this.#grids = data.grids.map((grid) => ({ ...grid, heights: Int32Array.from(grid.heights) }));
        this.#fadeInner = data.fadeInner;
        this.#fadeOuter = data.fadeOuter;
    }

    get isFlat() {
        return this.#grids.length === 0;
    }

    /** the fine grid (the first): the lawn is built on its triangles */
    get fine() {
        return this.#grids[0] ?? null;
    }

    heightAt(x, z) {
        if (this.#grids.length === 0) {
            return 0;
        }
        const grid = this.#grids.find((g) => covers(g, x, z)) ?? this.#grids[this.#grids.length - 1];
        const height = triangular(grid, x, z);
        const r = Math.hypot(x, z);
        if (r <= this.#fadeInner) {
            return height;
        }
        const t = Math.min(1, (r - this.#fadeInner) / (this.#fadeOuter - this.#fadeInner));

        return height * (1 - t * t * (3 - 2 * t));
    }

    /** The lowest ground under an outline ([[x, z], …] metres): where something upright stands. */
    lowestUnder(points) {
        if (this.isFlat || points.length === 0) {
            return 0;
        }

        return Math.min(...points.map(([x, z]) => this.heightAt(x, z)));
    }

    /**
     * The fine grid's triangles within a radius, as positions on the ground (x, y, z per
     * vertex, three per triangle, facing up): the lawn.
     */
    fineTriangles(radius) {
        const grid = this.fine;
        const positions = [];
        if (!grid) {
            return positions;
        }
        const node = (c, r) => [grid.originX + c * grid.spacing, grid.heights[r * grid.columns + c] / 100, grid.originZ + r * grid.spacing];
        for (let r = 0; r < grid.rows - 1; r++) {
            for (let c = 0; c < grid.columns - 1; c++) {
                const [x, z] = [grid.originX + (c + 0.5) * grid.spacing, grid.originZ + (r + 0.5) * grid.spacing];
                if (Math.hypot(x, z) > radius) {
                    continue;
                }
                // counter-clockwise seen from above (x east, z south): (c,r) → (c+1,r+1) → (c+1,r), then (c,r) → (c,r+1) → (c+1,r+1)
                positions.push(...node(c, r), ...node(c + 1, r + 1), ...node(c + 1, r));
                positions.push(...node(c, r), ...node(c, r + 1), ...node(c + 1, r + 1));
            }
        }

        return positions;
    }

    /**
     * A ground layer laid over the terrain. Within the fine grid it is cut into pieces that
     * each lie in one ground triangle, so it follows the lawn exactly; elsewhere it is
     * subdivided to maxEdge. Returns a new non-indexed geometry (the given one is disposed);
     * unchanged when the terrain is flat.
     */
    drape(geometry, maxEdge = TerrainField.DRAPE_EDGE) {
        if (this.isFlat) {
            return geometry;
        }
        geometry.computeBoundingBox();
        const box = geometry.boundingBox;
        const fine = this.fine;
        const inFine = fine && covers(fine, box.min.x, box.min.z) && covers(fine, box.max.x, box.max.z);
        const source = geometry.index ? geometry.toNonIndexed() : geometry;
        const cut = inFine ? cutAlongGrid(source, fine) : subdivide(source, maxEdge);
        if (source !== geometry) {
            source.dispose();
        }
        geometry.dispose();
        const position = cut.attributes.position;
        for (let i = 0; i < position.count; i++) {
            position.setY(i, position.getY(i) + this.heightAt(position.getX(i), position.getZ(i)));
        }
        cut.computeBoundingBox();
        cut.computeBoundingSphere();

        return cut;
    }
}

function covers(grid, x, z) {
    return x >= grid.originX && z >= grid.originZ
        && x <= grid.originX + (grid.columns - 1) * grid.spacing
        && z <= grid.originZ + (grid.rows - 1) * grid.spacing;
}

/** linear on the two triangles of a cell, split along u = v (as TerrainGrid::heightAt) */
function triangular(grid, x, z) {
    const u = THREE.MathUtils.clamp((x - grid.originX) / grid.spacing, 0, grid.columns - 1);
    const v = THREE.MathUtils.clamp((z - grid.originZ) / grid.spacing, 0, grid.rows - 1);
    const c = Math.min(Math.floor(u), grid.columns - 2);
    const r = Math.min(Math.floor(v), grid.rows - 2);
    const fu = u - c;
    const fv = v - r;
    const at = (col, row) => grid.heights[row * grid.columns + col] / 100;
    const h00 = at(c, r);
    const h11 = at(c + 1, r + 1);

    return fu >= fv
        ? h00 + fu * (at(c + 1, r) - h00) + fv * (h11 - at(c + 1, r))
        : h00 + fv * (at(c, r + 1) - h00) + fu * (h11 - at(c, r + 1));
}

/** the vertices of a non-indexed geometry as arrays of all their attribute values */
function readTriangles(geometry) {
    const names = Object.keys(geometry.attributes);
    const attributes = names.map((name) => geometry.attributes[name]);
    const offsets = [];
    let width = 0;
    for (const attribute of attributes) {
        offsets.push(width);
        width += attribute.itemSize;
    }
    const vertex = (index) => {
        const values = new Array(width);
        attributes.forEach((attribute, n) => {
            for (let k = 0; k < attribute.itemSize; k++) {
                values[offsets[n] + k] = attribute.array[index * attribute.itemSize + k];
            }
        });

        return values;
    };
    const triangles = [];
    for (let i = 0; i + 2 < geometry.attributes.position.count; i += 3) {
        triangles.push([vertex(i), vertex(i + 1), vertex(i + 2)]);
    }

    return { names, attributes, offsets, position: offsets[names.indexOf('position')], triangles };
}

function writeTriangles({ names, attributes, offsets }, triangles) {
    const result = new THREE.BufferGeometry();
    names.forEach((name, n) => {
        const size = attributes[n].itemSize;
        const array = new Float32Array(triangles.length * 3 * size);
        let i = 0;
        for (const triangle of triangles) {
            for (const values of triangle) {
                for (let k = 0; k < size; k++) {
                    array[i++] = values[offsets[n] + k];
                }
            }
        }
        result.setAttribute(name, new THREE.BufferAttribute(array, size, attributes[n].normalized));
    });

    return result;
}

const lerp = (a, b, t) => a.map((value, k) => value + (b[k] - value) * t);

/** Sutherland-Hodgman: the part of a polygon where f(vertex) ≥ 0 (f linear in position) */
function clip(polygon, f) {
    const result = [];
    for (let i = 0; i < polygon.length; i++) {
        const [a, b] = [polygon[i], polygon[(i + 1) % polygon.length]];
        const [fa, fb] = [f(a), f(b)];
        if (fa >= 0) {
            result.push(a);
        }
        if ((fa >= 0) !== (fb >= 0)) {
            result.push(lerp(a, b, fa / (fa - fb)));
        }
    }

    return result;
}

/**
 * Cuts every triangle along the grid's lines u = k, v = k and diagonals u − v = k (grid
 * units), so each piece lies in one ground triangle. Upright faces (the thin sides of a
 * slab) are cut across their length at the same lines.
 */
function cutAlongGrid(geometry, grid) {
    const data = readTriangles(geometry);
    const p = data.position;
    const u = (vertex) => (vertex[p] - grid.originX) / grid.spacing;
    const v = (vertex) => (vertex[p + 2] - grid.originZ) / grid.spacing;
    const output = [];
    const fan = (polygon) => {
        for (let k = 1; k + 1 < polygon.length; k++) {
            output.push([polygon[0], polygon[k], polygon[k + 1]]);
        }
    };
    for (const triangle of data.triangles) {
        const [a, b, c] = triangle;
        const planArea = (u(b) - u(a)) * (v(c) - v(a)) - (u(c) - u(a)) * (v(b) - v(a));
        if (Math.abs(planArea) < 1e-9) {
            // upright: cut along its plan direction wherever it crosses a grid line
            cutUpright(triangle, u, v, fan);
            continue;
        }
        const us = triangle.map(u);
        const vs = triangle.map(v);
        for (let r = Math.floor(Math.min(...vs)); r < Math.ceil(Math.max(...vs)); r++) {
            const row = clip(clip(triangle, (x) => v(x) - r), (x) => r + 1 - v(x));
            if (row.length < 3) {
                continue;
            }
            for (let col = Math.floor(Math.min(...us)); col < Math.ceil(Math.max(...us)); col++) {
                const cell = clip(clip(row, (x) => u(x) - col), (x) => col + 1 - u(x));
                if (cell.length < 3) {
                    continue;
                }
                // the cell's two triangles: u − v ≥ col − r (north-east of the diagonal) and the rest
                for (const piece of [clip(cell, (x) => u(x) - v(x) - (col - r)), clip(cell, (x) => (col - r) - (u(x) - v(x)))]) {
                    if (piece.length >= 3) {
                        fan(piece);
                    }
                }
            }
        }
    }

    return writeTriangles(data, output.filter((t) => planOrUpright(t, u, v, p)));
}

/** drops slivers the clipping leaves on an edge (no area in plan and no height) */
function planOrUpright([a, b, c], u, v, p) {
    const planArea = Math.abs((u(b) - u(a)) * (v(c) - v(a)) - (u(c) - u(a)) * (v(b) - v(a)));

    return planArea > 1e-10 || Math.max(a[p + 1], b[p + 1], c[p + 1]) - Math.min(a[p + 1], b[p + 1], c[p + 1]) > 1e-6;
}

function cutUpright(triangle, u, v, fan) {
    // the face's direction in plan: from its first vertex to the farthest one
    const [a] = triangle;
    const far = triangle.reduce((best, x) => (Math.hypot(u(x) - u(a), v(x) - v(a)) > Math.hypot(u(best) - u(a), v(best) - v(a)) ? x : best), a);
    const [du, dv] = [u(far) - u(a), v(far) - v(a)];
    const length = Math.hypot(du, dv);
    if (length < 1e-9) {
        fan(triangle);
        return;
    }
    const t = (x) => ((u(x) - u(a)) * du + (v(x) - v(a)) * dv) / (length * length);
    // where the face's plan segment crosses u = k, v = k, u − v = k
    const cuts = new Set([0, 1]);
    const crossings = (start, step) => {
        if (Math.abs(step) < 1e-12) {
            return;
        }
        for (let k = Math.ceil(Math.min(start, start + step)); k <= Math.floor(Math.max(start, start + step)); k++) {
            cuts.add((k - start) / step);
        }
    };
    crossings(u(a), du);
    crossings(v(a), dv);
    crossings(u(a) - v(a), du - dv);
    const sorted = [...cuts].filter((x) => x >= 0 && x <= 1).sort((x, y) => x - y);
    for (let i = 1; i < sorted.length; i++) {
        const piece = clip(clip(triangle, (x) => t(x) - sorted[i - 1]), (x) => sorted[i] - t(x));
        if (piece.length >= 3) {
            fan(piece);
        }
    }
}

/**
 * Splits every triangle at the middle of its longest edge (measured in plan, x/z) until no
 * edge is longer than maxEdge; every attribute is interpolated.
 */
function subdivide(geometry, maxEdge) {
    const data = readTriangles(geometry);
    const p = data.position;
    const limit = maxEdge * maxEdge;
    const planLength = (a, b) => (a[p] - b[p]) ** 2 + (a[p + 2] - b[p + 2]) ** 2;
    const output = [];
    const stack = [...data.triangles];
    while (stack.length > 0) {
        const [a, b, c] = stack.pop();
        const edges = [planLength(a, b), planLength(b, c), planLength(c, a)];
        const longest = edges.indexOf(Math.max(...edges));
        if (edges[longest] <= limit) {
            output.push([a, b, c]);
            continue;
        }
        // keep the winding: split edge (p, q) of triangle (p, q, r)
        const [x, y, z] = [[a, b, c], [b, c, a], [c, a, b]][longest];
        const m = lerp(x, y, 0.5);
        stack.push([x, m, z], [m, y, z]);
    }

    return writeTriangles(data, output);
}
