import * as THREE from 'three';
import { mergeGeometries } from 'three/examples/jsm/utils/BufferGeometryUtils.js';
import { STONES, applyMetricUVs, proceduralTexture } from './procedural_textures.js';
import { acrossFrom, bounds, contains, counterClockwise, edgeDistance, inwardNormal, polygonArea, seededRandom, triangulate } from './outline_geometry.js';

/** a part the server marks for this module (InterventionBuilder::APPEARANCE_KIND) */
export const RAIN_GARDEN = 'raingarden';

/** metres from the rim to where the floor starts: the banks, at most (narrow necks get less) */
const BANK = 0.9;
/** where the bank's profile is sampled, as shares of it: an edging at the rim, grass, planted mulch, river stones at its foot */
const RINGS = [0, 0.06, 0.07, 0.15, 0.3, 0.5, 0.7, 0.82, 0.92, 1];
const EDGING_TO = 0.065;
const GRASS_TO = 0.3;
const MULCH_TO = 0.78;
const STONES_TO = 0.95;
/** the rim sits this far above the ground, flush with the plot's slab (InterventionBuilder::PLOT_TOP + a little) */
const RIM_LIFT = 0.006;
/** a basin without a given depth (SiteType's default ponding depth) */
const DEFAULT_DEPTH = 0.3;
/**
 * Beyond the banks the floor dips a little more towards its middle, so the last of the water
 * stands in a pond there, not as a sheet over all of it: rings this far in (shares of the bank
 * width, no further than the outline allows), this much lower at the deepest.
 */
const DISH_RINGS = [1.8, 2.8, 4];
const DISH_DEPTH = 0.1;
/** how full the basins are drawn: a share of their depth above the deepest point, kept below the rim */
const DEFAULT_FILL = 0.08;
const FREEBOARD = 0.03;
/** Chaikin passes over the pond's shore */
const SHORE_SMOOTHING = 2;
/** no vertex moves inwards more than this share of the distance to the outline's far side */
const NECK_SHARE = 0.45;

const COLOURS = {
    lawn: new THREE.Color('#6f9a4a'),
    stones: new THREE.Color('#a39b8c'),
    mulch: new THREE.Color('#5b4634'),
    wetMulch: new THREE.Color('#4a3a2b'),
    /** low planting between the clumps: from afar a planted garden reads green, not as bare mulch */
    cover: new THREE.Color('#566f36'),
    edging: new THREE.Color('#bdb7ab'),
    gravel: new THREE.Color('#8f8a80'),
    sedgeBase: new THREE.Color('#41602a'),
    sedgeTip: new THREE.Color('#9fbf5c'),
    stem: new THREE.Color('#5d8a3a'),
};
/** the pebble texture's repeat, metres: tinted brown it reads as bark mulch, grey as gravel */
const GROUND_TEXTURE_METRES = 0.6;

/** the openings in the ground: a top-down mask, this many pixels a side, its outlines shrunk by its border */
const MASK_SIZE = 1024;
const MASK_MARGIN = 2;
const MASK_SHRINK_PIXELS = 1.5;

/** perennials of a rain garden: purple loosestrife and iris, yellow rudbeckia, white yarrow */
const BLOOMS = ['#8a5fb5', '#6d5bc4', '#e2b23a', '#f1eee4'].map((colour) => new THREE.Color(colour));
/**
 * Plants per square metre, by quality tier (detail 0…3). Never none: the planting is what a rain
 * garden is, and the governor steps the tier down when the camera comes close (more pixels to fill),
 * exactly when a person looks at it. Instanced, they cost a few draw calls on any tier.
 */
const DENSITY = [2, 3.5, 5, 6.5];
/** plants cast shadows from this detail on: the cost when they fill the screen */
const PLANT_SHADOWS_FROM = 3;
const SEDGE_SHARE = 0.45;

/**
 * Rain gardens as they are built: a sunken basin with sloping banks (lawn at the rim, a band of
 * river stones, mulch), a level floor below the lowest point of the rim, water standing in it
 * that meets the banks where they reach its level, and sedges and flowering perennials on the
 * banks. The plot's ground has a hole where a basin lies (SceneRenderer cuts it). All of it
 * follows the terrain at the rim; floor and water are level, as water is.
 */
export class RainGardens {
    group = new THREE.Group();

    #detail = 1;
    #fill = DEFAULT_FILL;
    #gardens = [];
    #terrain = null;
    #key = '';
    // both sides: a bank seen from below its rim (the camera low over the basin) still closes it
    #ground = new THREE.MeshStandardMaterial({ vertexColors: true, roughness: 1, metalness: 0, side: THREE.DoubleSide });
    // shallow water: the mulch shows through it, the sky in it
    #water = new THREE.MeshStandardMaterial({ color: '#1f3a42', roughness: 0.02, metalness: 0.55, transparent: true, opacity: 0.62, envMapIntensity: 1.8, side: THREE.DoubleSide, depthWrite: false });
    #plants = new THREE.MeshStandardMaterial({ vertexColors: true, roughness: 0.85, metalness: 0 });
    #sedge = sedgeGeometry();
    #perennial = perennialGeometry();
    #stone = stoneGeometry();
    #maskCanvas = document.createElement('canvas');
    #mask = new THREE.CanvasTexture(this.#maskCanvas);
    /** shared by every ground material patched to leave the openings: no recompile when they change */
    #openings = {
        openingMask: { value: this.#mask },
        openingMin: { value: new THREE.Vector2() },
        openingSize: { value: new THREE.Vector2(1, 1) },
        openingActive: { value: 0 },
    };

    constructor() {
        this.group.name = 'rain_gardens';
        this.#maskCanvas.width = MASK_SIZE;
        this.#maskCanvas.height = MASK_SIZE;
        this.#mask.flipY = false;
        this.#mask.generateMipmaps = false;
        this.#mask.minFilter = THREE.LinearFilter;
        const texture = proceduralTexture(STONES);
        if (texture) {
            Object.assign(this.#ground, { map: texture.map, bumpMap: texture.bumpMap, bumpScale: texture.bumpScale });
        }
    }

    /**
     * Lets a ground material (the lawn under the site) leave the basins open: its fragments inside
     * an outline are discarded, so the basin under it shows. Patch once; the openings follow.
     */
    openGround(material) {
        const openings = this.#openings;
        const previous = material.onBeforeCompile;
        material.onBeforeCompile = (shader, renderer) => {
            previous?.call(material, shader, renderer);
            Object.assign(shader.uniforms, openings);
            shader.vertexShader = shader.vertexShader
                .replace('#include <common>', '#include <common>\nvarying vec2 vOpeningXZ;')
                .replace('#include <project_vertex>', '#include <project_vertex>\nvOpeningXZ = (modelMatrix * vec4(transformed, 1.0)).xz;');
            shader.fragmentShader = shader.fragmentShader
                .replace('#include <common>', '#include <common>\nvarying vec2 vOpeningXZ;\nuniform sampler2D openingMask;\nuniform vec2 openingMin;\nuniform vec2 openingSize;\nuniform float openingActive;')
                .replace('#include <clipping_planes_fragment>', `#include <clipping_planes_fragment>
                    if (openingActive > 0.5) {
                        vec2 openingUv = (vOpeningXZ - openingMin) / openingSize;
                        if (all(greaterThanEqual(openingUv, vec2(0.0))) && all(lessThanEqual(openingUv, vec2(1.0))) && texture2D(openingMask, openingUv).r > 0.5) {
                            discard;
                        }
                    }`);
        };
        const key = material.customProgramCacheKey?.bind(material);
        material.customProgramCacheKey = () => `${key ? key() : ''}|ground-openings`;
        material.needsUpdate = true;
    }

    /** @param {number} detail the quality tier's detail (0…3): how densely the banks are planted */
    setDetail(detail) {
        if (detail !== this.#detail) {
            this.#detail = detail;
            this.#build();
        }
    }

    /** @param {number} fill how full the basins are, 0…1 of their depth */
    setFill(fill) {
        this.#fill = Math.max(0, Math.min(1, fill));
        this.#build();
    }

    /**
     * @param {{profile: number[][], appearance?: {depth?: number}}[]} parts the rain gardens' parts ([x, z] metres)
     * @param {{heightAt(x: number, z: number): number}} terrain
     */
    setGardens(parts, terrain) {
        const gardens = parts.filter((part) => (part.profile?.length ?? 0) >= 3);
        // the plot's preview rebuilds the product every frame of a drag: the basins only when they change
        const key = JSON.stringify(gardens.map((part) => [part.profile, part.appearance?.depth]));
        if (key === this.#key && terrain === this.#terrain) {
            return;
        }
        this.#key = key;
        this.#gardens = gardens;
        this.#terrain = terrain;
        this.#drawOpenings();
        this.#build();
    }

    /** the gardens' outlines, white on black, in a square over all of them */
    #drawOpenings() {
        const active = this.#gardens.length > 0;
        this.#openings.openingActive.value = active ? 1 : 0;
        if (!active) {
            return;
        }
        const [minX, maxX, minZ, maxZ] = bounds(this.#gardens.flatMap((part) => part.profile));
        const side = Math.max(maxX - minX, maxZ - minZ) + 2 * MASK_MARGIN;
        const [originX, originZ] = [(minX + maxX) / 2 - side / 2, (minZ + maxZ) / 2 - side / 2];
        this.#openings.openingMin.value.set(originX, originZ);
        this.#openings.openingSize.value.set(side, side);
        const context = this.#maskCanvas.getContext('2d');
        const scale = MASK_SIZE / side;
        context.globalCompositeOperation = 'source-over';
        context.fillStyle = '#000';
        context.fillRect(0, 0, MASK_SIZE, MASK_SIZE);
        const path = new Path2D();
        for (const part of this.#gardens) {
            part.profile.forEach(([x, z], i) => (i === 0 ? path.moveTo : path.lineTo).call(path, (x - originX) * scale, (z - originZ) * scale));
            path.closePath();
        }
        context.fillStyle = '#fff';
        context.fill(path);
        // a hair inside the rim: the lawn closes up to the bank, never a gap to look through
        context.globalCompositeOperation = 'destination-out';
        context.lineWidth = MASK_SHRINK_PIXELS * 2;
        context.stroke(path);
        context.globalCompositeOperation = 'destination-over';
        context.fillStyle = '#000';
        context.fillRect(0, 0, MASK_SIZE, MASK_SIZE);
        this.#mask.needsUpdate = true;
    }

    /** the outlines the ground must leave open ([[x, z], …] metres) */
    get outlines() {
        return this.#gardens.map((part) => part.profile);
    }

    dispose() {
        this.#clear();
        [this.#ground, this.#water, this.#plants].forEach((material) => material.dispose());
        [this.#sedge, this.#perennial.foliage, this.#perennial.heads, this.#stone].forEach((geometry) => geometry.dispose());
        this.#mask.dispose();
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
        if (!this.#terrain || this.#gardens.length === 0) {
            return;
        }
        const grounds = [];
        const waters = [];
        const sedges = [];
        const perennials = [];
        const stones = [];
        this.#gardens.forEach((part, index) => {
            const basin = new Basin(part.profile, part.appearance?.depth ?? DEFAULT_DEPTH, this.#terrain);
            grounds.push(basin.groundGeometry());
            const water = basin.waterGeometry(this.#fill);
            if (water) {
                waters.push(water);
            }
            basin.plant(DENSITY[this.#detail] ?? 0, seededRandom(1000 + index * 7919), sedges, perennials, this.#fill);
            basin.stones(seededRandom(5000 + index * 104729), stones);
        });

        const ground = new THREE.Mesh(mergeGeometries(grounds), this.#ground);
        ground.receiveShadow = true;
        this.group.add(ground);
        if (waters.length > 0) {
            const water = new THREE.Mesh(mergeGeometries(waters), this.#water);
            water.receiveShadow = true;
            water.renderOrder = 1;
            this.group.add(water);
        }
        // the perennials' foliage keeps its green; only the flower heads take each plant's colour
        const foliage = perennials.map((plant) => ({ ...plant, colour: plant.leaves }));
        this.group.add(...[[this.#sedge, sedges], [this.#perennial.foliage, foliage], [this.#perennial.heads, perennials], [this.#stone, stones]]
            .filter(([, plants]) => plants.length > 0)
            .map(([geometry, plants]) => this.#instances(geometry, plants)));
    }

    #instances(geometry, plants) {
        const mesh = new THREE.InstancedMesh(geometry, this.#plants, plants.length);
        const matrix = new THREE.Matrix4();
        const rotation = new THREE.Quaternion();
        const up = new THREE.Vector3(0, 1, 0);
        plants.forEach(({ x, y, z, scale, angle, colour }, i) => {
            matrix.compose(new THREE.Vector3(x, y, z), rotation.setFromAxisAngle(up, angle), new THREE.Vector3(scale, scale, scale));
            mesh.setMatrixAt(i, matrix);
            mesh.setColorAt(i, colour);
        });
        mesh.castShadow = this.#detail >= PLANT_SHADOWS_FROM;
        mesh.receiveShadow = true;

        return mesh;
    }
}

/**
 * One basin: its outline in rings stepping inwards and down. Each corner moves along its inward
 * normal, no further than the outline's far side allows (a narrow neck gets steep banks, not
 * crossed ones), and each ring sinks on a smooth profile from the rim to the level floor.
 */
class Basin {
    #outline;
    #depth;
    #terrain;
    #rings = [];
    #rim = [];
    #floor;
    #deepest;

    constructor(profile, depth, terrain) {
        this.#outline = counterClockwise(profile);
        this.#depth = Math.max(0.1, depth);
        this.#terrain = terrain;
        const count = this.#outline.length;
        const normals = this.#outline.map((_, i) => inwardNormal(this.#outline, i));
        // how wide the basin is across from each corner: the banks take no more than their share of it
        const across = this.#outline.map((_, i) => acrossFrom(this.#outline, i, normals[i], BANK * 2));
        const reach = across.map((width) => Math.min(BANK, NECK_SHARE * width));
        this.#rim = this.#outline.map(([x, z]) => terrain.heightAt(x, z) + RIM_LIFT);
        this.#floor = Math.min(...this.#rim) - this.#depth;
        this.#rings = RINGS.map((share) => this.#outline.map(([x, z], i) => {
            const [nx, nz] = normals[i];
            const offset = share * reach[i];

            return [x + nx * offset, this.#heightAt(i, share), z + nz * offset];
        }));
        // the dish: further rings dipping towards the middle, each corner no further in than its room
        const room = across.map((width) => NECK_SHARE * width);
        DISH_RINGS.forEach((share, k) => {
            const dip = DISH_DEPTH * (k + 1) / DISH_RINGS.length;
            this.#rings.push(this.#outline.map(([x, z], i) => {
                const offset = Math.max(reach[i], Math.min(share * BANK, room[i]));

                return [x + normals[i][0] * offset, this.#floor - dip, z + normals[i][1] * offset];
            }));
        });
        this.#deepest = this.#floor - DISH_DEPTH;
        this.reach = reach;
        this.normals = normals;
        this.count = count;
    }

    /** the bank's height at a corner, a share of the way from the rim to the floor */
    #heightAt(corner, share) {
        const eased = 0.5 - 0.5 * Math.cos(Math.PI * share);

        return this.#rim[corner] + (this.#floor - this.#rim[corner]) * eased;
    }

    /** where the water stands at a fill (a share of the depth above the deepest point), or null when dry */
    #waterLevel(fill) {
        const level = Math.min(this.#deepest + fill * (this.#depth + DISH_DEPTH), Math.min(...this.#rim) - FREEBOARD);

        return fill > 0 && level > this.#deepest + 0.005 ? level : null;
    }

    /** banks (rings stitched together) and floor, with the colours of what covers them */
    groundGeometry() {
        const positions = [];
        const colours = [];
        const colourAt = (share) => {
            if (share <= EDGING_TO) {
                return COLOURS.edging;
            }
            if (share <= GRASS_TO) {
                return COLOURS.lawn.clone().lerp(COLOURS.mulch, (share / GRASS_TO) ** 3);
            }
            if (share <= MULCH_TO) {
                return COLOURS.mulch;
            }
            if (share <= STONES_TO) {
                return COLOURS.gravel;
            }

            return COLOURS.wetMulch;
        };
        // mulch is never one flat brown: a seeded mottle per vertex, a little lighter or darker
        const random = seededRandom(Math.round(this.#outline[0][0] * 131 + this.#outline[0][1] * 977));
        const mottled = (colour) => {
            const tinted = colour.clone().multiplyScalar(0.85 + random() * 0.3);
            // mulch and the soil under the water are partly grown over
            return colour === COLOURS.mulch || colour === COLOURS.wetMulch ? tinted.lerp(COLOURS.cover, random() * 0.7) : tinted;
        };
        const push = (point, colour) => {
            positions.push(...point);
            colours.push(colour.r, colour.g, colour.b);
        };
        for (let k = 0; k < this.#rings.length - 1; k++) {
            const [outer, inner] = [this.#rings[k], this.#rings[k + 1]];
            const [outerColour, innerColour] = [colourAt(Math.min(1, RINGS[k] ?? 1)), colourAt(Math.min(1, RINGS[k + 1] ?? 1))];
            for (let i = 0; i < this.count; i++) {
                const j = (i + 1) % this.count;
                // counter-clockwise from above: the faces look up
                const [a, b, c, d] = [mottled(outerColour), mottled(innerColour), mottled(outerColour), mottled(innerColour)];
                for (const [point, colour] of [[outer[i], a], [inner[i], b], [outer[j], c], [outer[j], c], [inner[i], b], [inner[j], d]]) {
                    push(point, colour);
                }
            }
        }
        const floor = this.#rings[this.#rings.length - 1];
        for (const triangle of triangulate(floor)) {
            triangle.forEach((index) => push(floor[index], mottled(COLOURS.wetMulch)));
        }
        const geometry = new THREE.BufferGeometry();
        geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
        geometry.setAttribute('color', new THREE.Float32BufferAttribute(colours, 3));
        geometry.setAttribute('uv', new THREE.Float32BufferAttribute(new Float32Array((positions.length / 3) * 2), 2));
        geometry.computeVertexNormals();

        return applyMetricUVs(geometry, GROUND_TEXTURE_METRES);
    }

    /**
     * Level water, a share of the depth above the deepest point, reaching out to where the ground
     * is that high: along each corner's profile (banks, then the dish), the first ring below it.
     */
    waterGeometry(fill) {
        const level = this.#waterLevel(fill);
        if (null === level) {
            return null;
        }
        const shore = this.#outline.map((_, i) => {
            for (let k = 1; k < this.#rings.length; k++) {
                const [outer, inner] = [this.#rings[k - 1][i], this.#rings[k][i]];
                if (inner[1] <= level) {
                    const t = outer[1] === inner[1] ? 1 : (outer[1] - level) / (outer[1] - inner[1]);

                    return [outer[0] + (inner[0] - outer[0]) * t, level, outer[2] + (inner[2] - outer[2]) * t];
                }
            }
            const deepest = this.#rings[this.#rings.length - 1][i];

            return [deepest[0], level, deepest[2]];
        });
        // a pond's edge is soft: the shore's corners cut twice (it stays within the dish, which is lower)
        for (let pass = 0; pass < SHORE_SMOOTHING; pass++) {
            shore.splice(0, shore.length, ...shore.flatMap((point, i) => {
                const next = shore[(i + 1) % shore.length];

                return [[0.75 * point[0] + 0.25 * next[0], level, 0.75 * point[2] + 0.25 * next[2]], [0.25 * point[0] + 0.75 * next[0], level, 0.25 * point[2] + 0.75 * next[2]]];
            }));
        }
        const positions = triangulate(shore).flatMap((triangle) => triangle.flatMap((index) => shore[index]));
        const geometry = new THREE.BufferGeometry();
        geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
        geometry.computeVertexNormals();

        return geometry;
    }

    /** River stones along the foot of the banks, where the water comes in and runs along */
    stones(random, stones) {
        const greys = ['#a8a296', '#8d877c', '#bdb6a8', '#7d786f'].map((colour) => new THREE.Color(colour));
        for (let i = 0; i < this.count; i++) {
            const j = (i + 1) % this.count;
            const [ax, , az] = this.#rings[0][i];
            const [bx, , bz] = this.#rings[0][j];
            const per = Math.max(1, Math.round(Math.hypot(bx - ax, bz - az) * 6));
            for (let k = 0; k < per; k++) {
                const t = (k + random()) / per;
                const share = MULCH_TO + random() * (STONES_TO + 0.03 - MULCH_TO);
                const point = this.#pointAt(i, j, t, share);
                const scale = 0.6 + random() * 1.1;
                stones.push({ x: point[0], y: point[1] + 0.01 * scale, z: point[2], scale, angle: random() * Math.PI * 2, colour: greys[Math.floor(random() * greys.length)].clone().multiplyScalar(0.9 + random() * 0.2) });
            }
        }
    }

    /** a point on the bank between two corners (t along the edge) at a share of the way down */
    #pointAt(i, j, t, share) {
        const [x, z] = [this.#outline[i][0] + (this.#outline[j][0] - this.#outline[i][0]) * t, this.#outline[i][1] + (this.#outline[j][1] - this.#outline[i][1]) * t];
        const [nx, nz] = [this.normals[i][0] + (this.normals[j][0] - this.normals[i][0]) * t, this.normals[i][1] + (this.normals[j][1] - this.normals[i][1]) * t];
        const reach = this.reach[i] + (this.reach[j] - this.reach[i]) * t;
        const rim = this.#rim[i] + (this.#rim[j] - this.#rim[i]) * t;
        const eased = 0.5 - 0.5 * Math.cos(Math.PI * share);

        return [x + nx * share * reach, rim + (this.#floor - rim) * eased, z + nz * share * reach];
    }

    /**
     * Sedges and flowering perennials on the banks and along the floor's edge, scattered by a seeded
     * random: the same garden gets the same planting every time.
     */
    plant(density, random, sedges, perennials, fill) {
        const water = this.#waterLevel(fill);
        const area = polygonArea(this.#outline);
        const count = Math.round(area * density);
        const [minX, maxX, minZ, maxZ] = bounds(this.#outline);
        for (let tries = 0, planted = 0; planted < count && tries < count * 6; tries++) {
            const x = minX + random() * (maxX - minX);
            const z = minZ + random() * (maxZ - minZ);
            if (!contains(this.#outline, x, z)) {
                continue;
            }
            const fromEdge = edgeDistance(this.#outline, x, z);
            const share = Math.min(1, fromEdge / BANK);
            // the rim's lawn stays free; on the floor fewer, the pond's open water none
            if (share < 0.18 || (share >= 1 && random() < 0.5)) {
                continue;
            }
            const rim = this.#terrain.heightAt(x, z) + RIM_LIFT;
            const eased = 0.5 - 0.5 * Math.cos(Math.PI * share);
            const dish = share >= 1 ? DISH_DEPTH * Math.min(1, (fromEdge - BANK) / ((DISH_RINGS[DISH_RINGS.length - 1] - 1) * BANK)) : 0;
            const y = rim + (this.#floor - rim) * eased - dish;
            if (null !== water && y < water - 0.04) {
                continue;
            }
            const sedge = random() < SEDGE_SHARE || share >= 1;
            const colour = sedge
                ? new THREE.Color(1, 1, 1).multiplyScalar(0.85 + random() * 0.3)
                : BLOOMS[Math.floor(random() * BLOOMS.length)].clone().multiplyScalar(0.9 + random() * 0.2);
            const leaves = new THREE.Color(1, 1, 1).multiplyScalar(0.85 + random() * 0.3);
            (sedge ? sedges : perennials).push({ x, y, z, scale: 0.9 + random() * 0.6, angle: random() * Math.PI * 2, colour, leaves });
            planted++;
        }
    }
}

/**
 * A sedge clump: fourteen arching blades (0.35–0.6 m), dark at the root, light at the tip.
 * Every face in both windings with the normals up, as the lawn's tufts (lawn.js).
 */
function sedgeGeometry() {
    const positions = [];
    const colours = [];
    const random = seededRandom(23);
    for (let blade = 0; blade < 14; blade++) {
        const angle = (blade / 14) * Math.PI * 2 + random() * 0.6;
        const height = 0.35 + random() * 0.25;
        const width = 0.012 + random() * 0.006;
        const lean = height * (0.25 + random() * 0.45);
        const [dx, dz] = [Math.cos(angle), Math.sin(angle)];
        const points = [
            [-width, 0, 0], [width, 0, 0],
            [-width * 0.7, height * 0.5, lean * 0.25], [width * 0.7, height * 0.5, lean * 0.25],
            [-width * 0.35, height * 0.85, lean * 0.7], [width * 0.35, height * 0.85, lean * 0.7],
            [0, height * 0.92, lean * 1.15],
        ];
        const world = points.map(([u, y, w]) => [u * -dz + w * dx, y, u * dx + w * dz]);
        const shade = (y) => COLOURS.sedgeBase.clone().lerp(COLOURS.sedgeTip, y / height);
        for (const [a, b, c] of [[0, 1, 2], [1, 3, 2], [2, 3, 4], [3, 5, 4], [4, 5, 6]]) {
            for (const triangle of [[a, b, c], [a, c, b]]) {
                for (const index of triangle) {
                    positions.push(...world[index]);
                    const colour = shade(world[index][1]);
                    colours.push(colour.r, colour.g, colour.b);
                }
            }
        }
    }

    return withUpNormals(positions, colours);
}

/**
 * A perennial: a few leafy stems (0.4–0.7 m) with a flower head on each; the stems green, the
 * heads white, so each instance's colour tints only the flowers strongly.
 */
function perennialGeometry() {
    const foliage = [];
    const heads = [];
    const random = seededRandom(41);
    for (let stem = 0; stem < 5; stem++) {
        const angle = random() * Math.PI * 2;
        const lean = 0.05 + random() * 0.12;
        const height = 0.4 + random() * 0.3;
        const top = new THREE.Vector3(Math.cos(angle) * lean, height, Math.sin(angle) * lean);
        const stalk = new THREE.CylinderGeometry(0.004, 0.006, height, 4, 1, true);
        stalk.translate(0, height / 2, 0);
        stalk.applyMatrix4(new THREE.Matrix4().makeShear(0, 0, top.x / height, 0, top.z / height, 0));
        paint(stalk, COLOURS.stem);
        const head = new THREE.IcosahedronGeometry(0.035 + random() * 0.02, 0);
        head.scale(1, 0.6, 1).translate(top.x, top.y, top.z);
        paint(head, new THREE.Color(1, 1, 1));
        const leaf = new THREE.IcosahedronGeometry(0.06, 0);
        leaf.scale(1.4, 0.25, 0.6).rotateY(angle).translate(top.x * 0.4, height * 0.35, top.z * 0.4);
        paint(leaf, COLOURS.stem);
        const flat = (geometry) => (geometry.index ? geometry.toNonIndexed() : geometry);
        foliage.push(flat(stalk), flat(leaf));
        heads.push(flat(head));
    }
    const merged = { foliage: mergeGeometries(foliage), heads: mergeGeometries(heads) };
    [...foliage, ...heads].forEach((part) => part.dispose());

    return merged;
}

/** A river stone: a squashed, slightly irregular pebble, 6–14 cm across at scale 1. */
function stoneGeometry() {
    const geometry = new THREE.IcosahedronGeometry(0.05, 1);
    const position = geometry.attributes.position;
    const random = seededRandom(73);
    for (let i = 0; i < position.count; i++) {
        position.setXYZ(i, position.getX(i) * (1.1 + random() * 0.25), position.getY(i) * (0.45 + random() * 0.15), position.getZ(i) * (0.9 + random() * 0.2));
    }
    geometry.computeVertexNormals();
    paint(geometry, new THREE.Color('#a8a296'));

    return geometry.index ? geometry.toNonIndexed() : geometry;
}

function paint(geometry, colour) {
    const count = geometry.attributes.position.count;
    const colours = new Float32Array(count * 3);
    for (let i = 0; i < count; i++) {
        colours.set([colour.r, colour.g, colour.b], i * 3);
    }
    geometry.setAttribute('color', new THREE.BufferAttribute(colours, 3));
}

function withUpNormals(positions, colours) {
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
