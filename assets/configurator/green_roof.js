import * as THREE from 'three';
import { mergeGeometries } from 'three/examples/jsm/utils/BufferGeometryUtils.js';
import { LAWN, ROOF_GRAVEL, SEDUM, WEATHERING, proceduralTexture } from './procedural_textures.js';
import { acrossFrom, bounds, contains, counterClockwise, edgeDistance, inwardNormal, polygonArea, polygonSignedArea, seededRandom, triangulate } from './outline_geometry.js';

/** a part the server marks for this module (InterventionBuilder::APPEARANCE_KIND) */
export const GREEN_ROOF = 'greenroof';

/** the vegetation kinds (SiteObject's green roof kinds) */
const EXTENSIVE = 'extensive';
const INTENSIVE = 'intensive';
/** metres: the gravel strip along the edge (FLL) and the build-up, when the part does not say */
const DEFAULT_STRIP = 0.5;
const DEFAULT_THICKNESS = { [EXTENSIVE]: 0.12, [INTENSIVE]: 0.35 };
/** the gravel lies this far below the sedum's top; on an intensive roof this high above the roof */
const GRAVEL_BELOW_SEDUM = 0.03;
const INTENSIVE_GRAVEL = 0.08;
/** the edging between gravel and planting: an aluminium profile, a little prouder than the sedum */
const KERB_WIDTH = 0.04;
const KERB_PROUD = 0.015;
/** an intensive roof's substrate mounds above its edging (the steel L-profile holding it) */
const SUBSTRATE_ABOVE_KERB = 0.08;
/** a roof narrower than this across, or smaller, gets no strip: it is planted up to its edging */
const MIN_WIDTH_FOR_STRIP = 2.5;
const MIN_AREA_FOR_STRIP = 12;
/** an inset corner moves at most this many times the inset (a sharp corner would shoot out) */
const MAX_MITER = 3;
/** no corner moves inwards more than this share of the distance to the outline's far side */
const NECK_SHARE = 0.35;

/** metres one repeat of a texture covers (the flat roofs' gravel as SiteBuilder::GRAVEL_TEXTURE_SIZE) */
const SEDUM_METRES = 2;
const LAWN_METRES = 2.5;
const GRAVEL_METRES = 4;
/** the soft patches over a planted field: metres across, not a multiple of its texture's repeat */
const PATCH_METRES = 9.7;

const COLOURS = {
    gravel: '#c4bfb3',
    kerb: '#a9adb0',
    soil: '#4a3a2b',
    lawn: '#86a85c',
};
/** sedum cushions: mostly greens and bronze, a few in flower */
const SEDUM_TUFTS = ['#7f9a45', '#9bb54e', '#6f7d38', '#8a9c72', '#7f9a45', '#a3653c', '#e6c935', '#9bb54e'].map((colour) => new THREE.Color(colour));
const SHRUBS = ['#4f7a32', '#5d8a3a', '#3f6b2c', '#6b8f3e', '#7a5e3a', '#56703a'].map((colour) => new THREE.Color(colour));
const BLOOMS = ['#8a5fb5', '#e2b23a', '#f1eee4', '#d65a5a', '#6d5bc4'].map((colour) => new THREE.Color(colour));

/**
 * Plants per square metre of planted roof, by quality tier (detail 0…3). Never none: the planting
 * is what a green roof is. Instanced, they cost a few draw calls on any tier.
 */
const TUFT_DENSITY = [0.5, 1, 1.8, 2.8];
const SHRUB_DENSITY = [0.05, 0.08, 0.12, 0.16];
const PERENNIAL_DENSITY = [0.25, 0.4, 0.6, 0.9];
const GRASS_DENSITY = [0.2, 0.35, 0.55, 0.8];
/** a school's main roof has 2,400 m²: at most this many of each plant over all roofs */
const MAX_INSTANCES = 15000;
/** the fewest plants of a kind an intensive roof gets, however small */
const MIN_PLANTS = 3;
/** the intensive roof's planting stands in beds: one per this many m², this far from the edging */
const BED_EVERY_M2 = 45;
const BED_RADIUS = [2, 5];
const BED_EDGE_CLEARANCE = 1.2;
const PLANT_EDGE_CLEARANCE = { tuft: 0.15, shrub: 0.6, plant: 0.35 };
/** plants cast shadows from this detail on (shrubs one tier earlier) */
const PLANT_SHADOWS_FROM = 3;

/**
 * Green roofs as they are built on a flat roof: the FLL gravel strip along the edge, an aluminium
 * edging, and inside it the planting. Extensive: a sedum carpet in drifts of greens, bronze and red,
 * some in flower, with low cushions. Intensive: a deeper substrate showing above its edging, lawn,
 * and beds of shrubs, perennials and ornamental grasses. The part's slab is not drawn; this is.
 */
export class GreenRoofs {
    group = new THREE.Group();

    #detail = 1;
    #roofs = [];
    #key = '';
    #textures = [];
    #gravel = this.#textured(ROOF_GRAVEL, COLOURS.gravel, GRAVEL_METRES, 1);
    #sedum = this.#mottled(this.#textured(SEDUM, '#ffffff', SEDUM_METRES, 1), SEDUM_METRES, 'green-roof-sedum');
    #lawn = this.#mottled(this.#textured(LAWN, COLOURS.lawn, LAWN_METRES, 1), LAWN_METRES, 'green-roof-lawn');
    #kerb = new THREE.MeshStandardMaterial({ color: COLOURS.kerb, roughness: 0.45, metalness: 0.6, side: THREE.DoubleSide });
    #soil = new THREE.MeshStandardMaterial({ color: COLOURS.soil, roughness: 1, metalness: 0, side: THREE.DoubleSide });
    #plants = new THREE.MeshStandardMaterial({ vertexColors: true, roughness: 0.9, metalness: 0 });
    #tuft = tuftGeometry();
    #shrub = shrubGeometry();
    #perennial = perennialGeometry();
    #grass = grassGeometry();

    constructor() {
        this.group.name = 'green_roofs';
    }

    /** @param {number} detail the quality tier's detail (0…3): how densely the roofs are planted */
    setDetail(detail) {
        if (detail !== this.#detail) {
            this.#detail = detail;
            this.#build();
        }
    }

    /**
     * @param {{profile: number[][], position: number[], depth: number, appearance?: {vegetation?: string, strip?: number, thickness?: number}}[]} parts
     *        the green roofs' slabs: the roof level's outline ([x, z] metres), lying flat from the roof's top up by depth
     */
    setRoofs(parts) {
        const roofs = parts.filter((part) => (part.profile?.length ?? 0) >= 3);
        const key = JSON.stringify(roofs.map((part) => [part.profile, part.position?.[1], part.depth, part.appearance]));
        if (key === this.#key) {
            return;
        }
        this.#key = key;
        this.#roofs = roofs;
        this.#build();
    }

    dispose() {
        this.#clear();
        [this.#gravel, this.#sedum, this.#lawn, this.#kerb, this.#soil, this.#plants].forEach((material) => material.dispose());
        [this.#tuft, this.#shrub, this.#perennial.foliage, this.#perennial.heads, this.#grass].forEach((geometry) => geometry.dispose());
    }

    #clear() {
        this.group.traverse((object) => {
            if (object.isMesh && !object.isInstancedMesh) {
                object.geometry.dispose();
            }
        });
        this.group.clear();
    }

    #build() {
        this.#clear();
        if (this.#roofs.length === 0) {
            return;
        }
        const surfaces = { gravel: new Surface(GRAVEL_METRES), kerb: new Surface(1), sedum: new Surface(SEDUM_METRES), lawn: new Surface(LAWN_METRES), soil: new Surface(1) };
        const plants = { tufts: [], shrubs: [], foliage: [], heads: [], grasses: [] };
        const detail = Math.max(0, Math.min(3, this.#detail));
        this.#roofs.forEach((part, index) => {
            const roof = new Roof(part);
            roof.build(surfaces);
            roof.plant(detail, seededRandom(7000 + index * 7919 + Math.round(Math.abs(part.profile[0][0] * 131 + part.profile[0][1] * 977))), plants);
        });

        for (const [name, material] of [['gravel', this.#gravel], ['kerb', this.#kerb], ['sedum', this.#sedum], ['lawn', this.#lawn], ['soil', this.#soil]]) {
            const geometry = surfaces[name].geometry();
            if (geometry) {
                const mesh = new THREE.Mesh(geometry, material);
                mesh.receiveShadow = true;
                mesh.castShadow = name === 'kerb' || name === 'soil';
                this.group.add(mesh);
            }
        }
        const instanced = [
            [this.#tuft, plants.tufts, false],
            [this.#shrub, plants.shrubs, detail >= PLANT_SHADOWS_FROM - 1],
            [this.#perennial.foliage, plants.foliage, detail >= PLANT_SHADOWS_FROM],
            [this.#perennial.heads, plants.heads, false],
            [this.#grass, plants.grasses, detail >= PLANT_SHADOWS_FROM],
        ];
        for (const [geometry, list, shadows] of instanced) {
            if (list.length > 0) {
                this.group.add(this.#instances(geometry, list.slice(0, MAX_INSTANCES), shadows));
            }
        }
    }

    #instances(geometry, plants, shadows) {
        const mesh = new THREE.InstancedMesh(geometry, this.#plants, plants.length);
        const matrix = new THREE.Matrix4();
        const rotation = new THREE.Quaternion();
        const up = new THREE.Vector3(0, 1, 0);
        const position = new THREE.Vector3();
        const scale = new THREE.Vector3();
        plants.forEach(({ x, y, z, scale: s, flat = 1, angle, colour }, i) => {
            matrix.compose(position.set(x, y, z), rotation.setFromAxisAngle(up, angle), scale.set(s, s * flat, s));
            mesh.setMatrixAt(i, matrix);
            mesh.setColorAt(i, colour);
        });
        mesh.castShadow = shadows;
        mesh.receiveShadow = true;

        return mesh;
    }

    #textured(name, colour, metres, roughness) {
        const material = new THREE.MeshStandardMaterial({ color: colour, roughness, metalness: 0, side: THREE.DoubleSide });
        const texture = proceduralTexture(name);
        if (texture) {
            Object.assign(material, { map: texture.map, bumpMap: texture.bumpMap, bumpScale: texture.bumpScale });
        }
        material.userData.textureMetres = metres;

        return material;
    }

    /**
     * Drifts metres across over a planted field, at a scale its texture does not share: lighter and
     * darker, greener and more bronze, so a large roof shows no repeat and reads as grown.
     */
    #mottled(material, repeat, key) {
        const { bumpMap: patches } = proceduralTexture(WEATHERING) ?? {};
        if (!patches) {
            return material;
        }
        material.onBeforeCompile = (shader) => {
            shader.uniforms.patchMap = { value: patches };
            shader.uniforms.patchScale = { value: repeat / PATCH_METRES };
            shader.fragmentShader = shader.fragmentShader
                .replace('#include <common>', '#include <common>\nuniform sampler2D patchMap;\nuniform float patchScale;')
                .replace('#include <map_fragment>', `#include <map_fragment>
                    float patchLight = texture2D(patchMap, vMapUv * patchScale).r;
                    float patchTone = smoothstep(0.38, 0.62, texture2D(patchMap, vMapUv * patchScale * 0.41 + vec2(0.31, 0.67)).r);
                    diffuseColor.rgb *= mix(vec3(1.05, 0.97, 0.88), vec3(0.94, 1.04, 0.94), patchTone) * mix(0.86, 1.14, patchLight);`);
        };
        material.customProgramCacheKey = () => key;

        return material;
    }
}

/**
 * Triangles of one material, gathered over every roof: positions, normals and UVs in metres of its
 * texture (tops in plan, walls along their run and up).
 */
class Surface {
    #positions = [];
    #normals = [];
    #uvs = [];
    #metres;

    constructor(metres) {
        this.#metres = metres;
    }

    /** a flat ring between two outlines of the same corners at height y, facing up */
    ring(outer, inner, y) {
        const count = outer.length;
        for (let i = 0; i < count; i++) {
            const j = (i + 1) % count;
            this.#flat([outer[i], inner[i], outer[j]], y);
            this.#flat([outer[j], inner[i], inner[j]], y);
        }
    }

    /** a flat polygon at height y, facing up */
    polygon(outline, y) {
        const ring = outline.map(([x, z]) => [x, y, z]);
        for (const triangle of triangulate(ring)) {
            this.#flat(triangle.map((index) => outline[index]), y);
        }
    }

    /**
     * Walls along an outline from y0 up to y1, facing out of it (outward = true) or into it.
     * The outline runs counter-clockwise from above.
     */
    walls(outline, y0, y1, outward) {
        if (y1 - y0 < 1e-4) {
            return;
        }
        const count = outline.length;
        let along = 0;
        for (let i = 0; i < count; i++) {
            const [ax, az] = outline[i];
            const [bx, bz] = outline[(i + 1) % count];
            const length = Math.hypot(bx - ax, bz - az);
            if (length < 1e-6) {
                continue;
            }
            // counter-clockwise from above (shoelace over [x, z] negative): the outside is to the left of each edge in x/z
            const sign = outward ? 1 : -1;
            const [nx, nz] = [sign * -(bz - az) / length, sign * (bx - ax) / length];
            const [u0, u1] = [along / this.#metres, (along + length) / this.#metres];
            const [v0, v1] = [y0 / this.#metres, y1 / this.#metres];
            const corners = [[ax, y0, az, u0, v0], [bx, y0, bz, u1, v0], [bx, y1, bz, u1, v1], [ax, y1, az, u0, v1]];
            for (const index of [0, 1, 2, 0, 2, 3]) {
                const [x, y, z, u, v] = corners[index];
                this.#positions.push(x, y, z);
                this.#normals.push(nx, 0, nz);
                this.#uvs.push(u, v);
            }
            along += length;
        }
    }

    geometry() {
        if (this.#positions.length === 0) {
            return null;
        }
        const geometry = new THREE.BufferGeometry();
        geometry.setAttribute('position', new THREE.Float32BufferAttribute(this.#positions, 3));
        geometry.setAttribute('normal', new THREE.Float32BufferAttribute(this.#normals, 3));
        geometry.setAttribute('uv', new THREE.Float32BufferAttribute(this.#uvs, 2));

        return geometry;
    }

    #flat(points, y) {
        // counter-clockwise from above, whichever way it was built
        const [[ax, az], [bx, bz], [cx, cz]] = points;
        const ordered = (bz - az) * (cx - ax) - (bx - ax) * (cz - az) < 0 ? [points[0], points[2], points[1]] : points;
        for (const [x, z] of ordered) {
            this.#positions.push(x, y, z);
            this.#normals.push(0, 1, 0);
            this.#uvs.push(x / this.#metres, z / this.#metres);
        }
    }
}

/** One roof level with its green roof: the outlines of strip, edging and planting, and their heights. */
class Roof {
    constructor(part) {
        this.vegetation = part.appearance?.vegetation === INTENSIVE ? INTENSIVE : EXTENSIVE;
        this.thickness = Math.max(0.05, part.appearance?.thickness ?? part.depth ?? DEFAULT_THICKNESS[this.vegetation]);
        // the slab lies flat, its middle half its depth above the roof's top (Slab::onGround)
        this.base = (part.position?.[1] ?? 0) - (part.depth ?? this.thickness) / 2;
        this.outline = counterClockwise(part.profile);
        const strip = Math.max(0, part.appearance?.strip ?? DEFAULT_STRIP);
        const [minX, maxX, minZ, maxZ] = bounds(this.outline);
        const roomy = polygonArea(this.outline) >= MIN_AREA_FOR_STRIP && Math.min(maxX - minX, maxZ - minZ) >= MIN_WIDTH_FOR_STRIP;
        // the strip's inner edge, and the edging's inner edge (where the planting starts)
        this.strip = roomy && strip > 0 ? inset(this.outline, strip) : null;
        this.planting = inset(this.strip ?? this.outline, KERB_WIDTH) ?? this.strip ?? this.outline;
        this.top = this.base + this.thickness;
        if (this.vegetation === INTENSIVE) {
            this.gravelY = this.base + INTENSIVE_GRAVEL;
            this.kerbY = Math.max(this.gravelY + 0.02, this.top - SUBSTRATE_ABOVE_KERB);
        } else {
            this.gravelY = this.top - GRAVEL_BELOW_SEDUM;
            this.kerbY = this.top + KERB_PROUD;
        }
    }

    build({ gravel, kerb, sedum, lawn, soil }) {
        const edge = this.strip ?? this.outline;
        if (this.strip) {
            // the washed gravel of the strip, its outer face down to the roof
            gravel.ring(this.outline, this.strip, this.gravelY);
            gravel.walls(this.outline, this.base, this.gravelY, true);
            kerb.walls(edge, this.gravelY, this.kerbY, true);
        } else {
            // planted up to the edge: the edging stands on the roof
            kerb.walls(edge, this.base, this.kerbY, true);
        }
        kerb.ring(edge, this.planting, this.kerbY);
        if (this.vegetation === INTENSIVE) {
            // the substrate mounds above its edging, soil showing on its side
            soil.walls(this.planting, this.kerbY, this.top, true);
            lawn.polygon(this.planting, this.top);
        } else {
            kerb.walls(this.planting, this.top, this.kerbY, false);
            sedum.polygon(this.planting, this.top);
        }
    }

    /**
     * Sedum cushions over an extensive roof; beds of shrubs, perennials and grasses on an intensive
     * one. A seeded random: the same roof gets the same planting every time.
     */
    plant(detail, random, plants) {
        const area = polygonArea(this.planting);
        if (this.vegetation === EXTENSIVE) {
            const count = Math.round(area * TUFT_DENSITY[detail]);
            this.#scatter(count, random, PLANT_EDGE_CLEARANCE.tuft, null, (x, z) => {
                const colour = SEDUM_TUFTS[Math.floor(random() * SEDUM_TUFTS.length)].clone().multiplyScalar(0.85 + random() * 0.3);
                plants.tufts.push({ x, y: this.top, z, scale: 0.8 + random() * 1.4, flat: 0.7 + random() * 0.6, angle: random() * Math.PI * 2, colour });
            });

            return;
        }
        const beds = this.#beds(area, random);
        const at = (density) => Math.max(MIN_PLANTS, Math.round(area * density[detail]));
        this.#scatter(at(SHRUB_DENSITY), random, PLANT_EDGE_CLEARANCE.shrub, { beds, spread: 0.6 }, (x, z) => {
            plants.shrubs.push({ x, y: this.top, z, scale: 0.6 + random() * 0.9, flat: 0.8 + random() * 0.4, angle: random() * Math.PI * 2, colour: SHRUBS[Math.floor(random() * SHRUBS.length)].clone().multiplyScalar(0.85 + random() * 0.3) });
        });
        this.#scatter(at(PERENNIAL_DENSITY), random, PLANT_EDGE_CLEARANCE.plant, { beds, spread: 1 }, (x, z) => {
            const plant = { x, y: this.top, z, scale: 0.8 + random() * 0.5, angle: random() * Math.PI * 2 };
            plants.foliage.push({ ...plant, colour: new THREE.Color(1, 1, 1).multiplyScalar(0.8 + random() * 0.3) });
            plants.heads.push({ ...plant, colour: BLOOMS[Math.floor(random() * BLOOMS.length)].clone().multiplyScalar(0.9 + random() * 0.2) });
        });
        this.#scatter(at(GRASS_DENSITY), random, PLANT_EDGE_CLEARANCE.plant, { beds, spread: 1.1 }, (x, z) => {
            plants.grasses.push({ x, y: this.top, z, scale: 0.8 + random() * 0.6, angle: random() * Math.PI * 2, colour: new THREE.Color(1, 1, 1).multiplyScalar(0.85 + random() * 0.3) });
        });
    }

    /** where the beds lie: centres well inside the edging, each with its radius */
    #beds(area, random) {
        const beds = [];
        const wanted = Math.max(1, Math.round(area / BED_EVERY_M2));
        const [minX, maxX, minZ, maxZ] = bounds(this.planting);
        for (let tries = 0; beds.length < wanted && tries < wanted * 20; tries++) {
            const [x, z] = [minX + random() * (maxX - minX), minZ + random() * (maxZ - minZ)];
            const clearance = contains(this.planting, x, z) ? edgeDistance(this.planting, x, z) : 0;
            // a narrow roof has no room that far in: later tries settle for less
            if (clearance >= (tries < wanted * 10 ? BED_EDGE_CLEARANCE : BED_EDGE_CLEARANCE / 3)) {
                beds.push({ x, z, r: Math.min(clearance, BED_RADIUS[0] + random() * (BED_RADIUS[1] - BED_RADIUS[0])) });
            }
        }

        return beds;
    }

    /** places up to count points inside the planting, clear of its edging, in the beds when given */
    #scatter(count, random, clearance, beds, place) {
        const [minX, maxX, minZ, maxZ] = bounds(this.planting);
        for (let tries = 0, placed = 0; placed < count && tries < count * 6; tries++) {
            let x;
            let z;
            if (beds && beds.beds.length > 0) {
                const bed = beds.beds[Math.floor(random() * beds.beds.length)];
                const angle = random() * Math.PI * 2;
                const d = bed.r * beds.spread * Math.sqrt(random());
                [x, z] = [bed.x + Math.cos(angle) * d, bed.z + Math.sin(angle) * d];
            } else {
                [x, z] = [minX + random() * (maxX - minX), minZ + random() * (maxZ - minZ)];
            }
            if (!contains(this.planting, x, z) || edgeDistance(this.planting, x, z) < clearance) {
                continue;
            }
            place(x, z);
            placed++;
        }
    }
}

/**
 * The outline moved inwards by distance: each corner along its inward bisector, as far as it takes
 * to keep both edges that far (at most MAX_MITER times), never further than its share of the way
 * across. Null when what is left is no longer the outline shrunk (it folded over or nearly vanished):
 * then half the distance is tried once.
 */
function inset(outline, distance, retry = true) {
    const count = outline.length;
    const moved = outline.map(([x, z], i) => {
        const normal = inwardNormal(outline, i);
        const [nx, nz] = normal;
        // the next edge's own inward normal: the bisector's angle to it is half the corner's turn
        const [bx, bz] = outline[(i + 1) % count];
        const length = Math.hypot(bx - x, bz - z) || 1;
        let [ex, ez] = [(bz - z) / length, -(bx - x) / length];
        if (ex * nx + ez * nz < 0) {
            [ex, ez] = [-ex, -ez];
        }
        const miter = Math.min(MAX_MITER, 1 / Math.max(1 / MAX_MITER, ex * nx + ez * nz));
        const offset = Math.min(distance * miter, NECK_SHARE * acrossFrom(outline, i, normal, distance * miter * 4));

        return [x + nx * offset, z + nz * offset];
    });
    const valid = Math.sign(polygonSignedArea(moved)) === Math.sign(polygonSignedArea(outline))
        && polygonArea(moved) >= 0.25 * polygonArea(outline)
        && !selfIntersects(moved);
    if (valid) {
        return moved;
    }

    return retry ? inset(outline, distance / 2, false) : null;
}

function selfIntersects(points) {
    const count = points.length;
    const cross = ([ax, az], [bx, bz], [cx, cz]) => (bx - ax) * (cz - az) - (bz - az) * (cx - ax);
    for (let i = 0; i < count; i++) {
        const [a, b] = [points[i], points[(i + 1) % count]];
        for (let j = i + 2; j < count; j++) {
            if ((j + 1) % count === i) {
                continue;
            }
            const [c, d] = [points[j], points[(j + 1) % count]];
            if (cross(a, b, c) * cross(a, b, d) < 0 && cross(c, d, a) * cross(c, d, b) < 0) {
                return true;
            }
        }
    }

    return false;
}

/** A sedum cushion: a low, lumpy dome about 0.25 m across at scale 1, a little darker at its foot. */
function tuftGeometry() {
    const geometry = new THREE.IcosahedronGeometry(0.12, 1);
    const position = geometry.attributes.position;
    const random = seededRandom(91);
    for (let i = 0; i < position.count; i++) {
        const y = position.getY(i);
        // the lower half pressed flat into the substrate
        position.setXYZ(i, position.getX(i) * (0.9 + random() * 0.2), Math.max(0, y) * (0.45 + random() * 0.15), position.getZ(i) * (0.9 + random() * 0.2));
    }
    geometry.computeVertexNormals();
    shadeByHeight(geometry, 0.7, 1.05, 0.055);

    return geometry.index ? geometry.toNonIndexed() : geometry;
}

/** A shrub: a few overlapping leafy balls, about 1 m across and 0.8 m high at scale 1. */
function shrubGeometry() {
    const random = seededRandom(57);
    const balls = [];
    for (let k = 0; k < 5; k++) {
        const radius = 0.25 + random() * 0.15;
        const angle = random() * Math.PI * 2;
        const d = k === 0 ? 0 : 0.2 + random() * 0.15;
        const ball = new THREE.IcosahedronGeometry(radius, 1);
        const position = ball.attributes.position;
        for (let i = 0; i < position.count; i++) {
            const jitter = 0.88 + random() * 0.24;
            position.setXYZ(i, position.getX(i) * jitter, position.getY(i) * jitter, position.getZ(i) * jitter);
        }
        ball.translate(Math.cos(angle) * d, radius * (k === 0 ? 1.3 : 0.95), Math.sin(angle) * d);
        balls.push(ball.index ? ball.toNonIndexed() : ball);
    }
    const geometry = mergeGeometries(balls);
    balls.forEach((ball) => ball.dispose());
    geometry.computeVertexNormals();
    shadeByHeight(geometry, 0.6, 1.1, 0.8);

    return geometry;
}

/**
 * A perennial clump: a mound of leaves, and stems with a flower head on each; the leaves keep
 * their green (tinted lightly per instance), the heads take each plant's bloom colour.
 */
function perennialGeometry() {
    const random = seededRandom(43);
    const leaf = new THREE.Color('#5d8a3a');
    const mound = new THREE.IcosahedronGeometry(0.22, 1);
    mound.scale(1, 0.6, 1).translate(0, 0.1, 0);
    paint(mound, leaf);
    const foliage = [mound.index ? mound.toNonIndexed() : mound];
    const heads = [];
    for (let stem = 0; stem < 6; stem++) {
        const angle = random() * Math.PI * 2;
        const lean = 0.05 + random() * 0.12;
        const height = 0.35 + random() * 0.25;
        const top = new THREE.Vector3(Math.cos(angle) * lean, height, Math.sin(angle) * lean);
        const stalk = new THREE.CylinderGeometry(0.004, 0.006, height, 4, 1, true);
        stalk.translate(0, height / 2, 0);
        stalk.applyMatrix4(new THREE.Matrix4().makeShear(0, 0, top.x / height, 0, top.z / height, 0));
        paint(stalk, leaf);
        foliage.push(stalk.index ? stalk.toNonIndexed() : stalk);
        const head = new THREE.IcosahedronGeometry(0.035 + random() * 0.02, 0);
        head.scale(1, 0.6, 1).translate(top.x, top.y, top.z);
        paint(head, new THREE.Color(1, 1, 1));
        heads.push(head.index ? head.toNonIndexed() : head);
    }
    const merged = { foliage: mergeGeometries(foliage), heads: mergeGeometries(heads) };
    [...foliage, ...heads].forEach((part) => part.dispose());

    return merged;
}

/**
 * An ornamental grass: twenty upright, arching blades (0.6–0.9 m), green at the root, straw at the tip.
 * Every face in both windings with the normals up, as the rain garden's sedges.
 */
function grassGeometry() {
    const base = new THREE.Color('#4f6b2c');
    const tip = new THREE.Color('#c9b26a');
    const positions = [];
    const colours = [];
    const random = seededRandom(29);
    for (let blade = 0; blade < 20; blade++) {
        const angle = (blade / 20) * Math.PI * 2 + random() * 0.5;
        const height = 0.6 + random() * 0.3;
        const width = 0.01 + random() * 0.005;
        const lean = height * (0.12 + random() * 0.25);
        const [dx, dz] = [Math.cos(angle), Math.sin(angle)];
        const points = [
            [-width, 0, 0], [width, 0, 0],
            [-width * 0.7, height * 0.5, lean * 0.2], [width * 0.7, height * 0.5, lean * 0.2],
            [-width * 0.35, height * 0.85, lean * 0.6], [width * 0.35, height * 0.85, lean * 0.6],
            [0, height, lean],
        ];
        const world = points.map(([u, y, w]) => [u * -dz + w * dx, y, u * dx + w * dz]);
        for (const [a, b, c] of [[0, 1, 2], [1, 3, 2], [2, 3, 4], [3, 5, 4], [4, 5, 6]]) {
            for (const triangle of [[a, b, c], [a, c, b]]) {
                for (const index of triangle) {
                    positions.push(...world[index]);
                    const colour = base.clone().lerp(tip, world[index][1] / height);
                    colours.push(colour.r, colour.g, colour.b);
                }
            }
        }
    }
    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
    geometry.setAttribute('color', new THREE.Float32BufferAttribute(colours, 3));
    const normals = new Float32Array(positions.length);
    for (let i = 1; i < normals.length; i += 3) {
        normals[i] = 1;
    }
    geometry.setAttribute('normal', new THREE.BufferAttribute(normals, 3));

    return geometry;
}

/** white vertex colours, darker towards the foot (from `low` at y 0 to `high` at `height`) */
function shadeByHeight(geometry, low, high, height) {
    const position = geometry.attributes.position;
    const colours = new Float32Array(position.count * 3);
    for (let i = 0; i < position.count; i++) {
        const shade = low + (high - low) * Math.min(1, Math.max(0, position.getY(i) / height));
        colours.set([shade, shade, shade], i * 3);
    }
    geometry.setAttribute('color', new THREE.BufferAttribute(colours, 3));
}

function paint(geometry, colour) {
    const count = geometry.attributes.position.count;
    const colours = new Float32Array(count * 3);
    for (let i = 0; i < count; i++) {
        colours.set([colour.r, colour.g, colour.b], i * 3);
    }
    geometry.setAttribute('color', new THREE.BufferAttribute(colours, 3));
}
