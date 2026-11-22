import * as THREE from 'three';
import { TerrainField } from './terrain.js';
import { LAWN_OFFSET } from './ground_layers.js';
import { LAWN, applyMetricUVs, proceduralTexture } from './procedural_textures.js';

/**
 * The lawn around the product: a grid with vertex colours (patches, mowing
 * stripes) under a fine grass texture, plus, on the higher quality tiers,
 * instanced grass tufts near the product. Tufts keep clear of whatever stands
 * on the ground (terrace, house, posts): setFootprints() passes their outlines.
 */
/** blades per tuft and their colours from root to tip (the ground's colour times its texture) */
const TUFT_BLADES = 5;
const TUFT_BASE = 0x41602a;
const TUFT_TIP = 0x6c8c3c;

export class Lawn {
    /** a disc that ends where the hills begin (Landscape.HILLS_INNER), so their rise never cuts through it */
    static RADIUS = 112;
    static SEGMENTS = 120;
    /** rings of vertices, for the mown stripes and patches drawn in vertex colours */
    static RINGS = 80;
    static TEXTURE_SIZE = 5;
    static COLOUR = 0x6f8f4c;
    /** a city's grass: less lush, worn in patches */
    static URBAN_COLOUR = 0x76884f;
    static STRIPE_WIDTH = 1.4;
    /** tufts per quality detail level (0–3) */
    static TUFTS = [0, 0, 9000, 16000];
    static TUFT_RADIUS = 11;

    #scene;
    #ground;
    #tufts = null;
    #detail = 1;
    #footprints = [];
    #centre = new THREE.Vector2();
    #urban = false;
    #terrain = TerrainField.FLAT;

    constructor(scene) {
        this.#scene = scene;
        this.#ground = this.#createGround();
        scene.add(this.#ground);
    }

    setDetail(detail) {
        this.#detail = detail;
        this.#rebuildTufts();
    }

    /** City grass (an aerial view of a real site) instead of a mown garden lawn. */
    setUrban(urban) {
        if (urban === this.#urban) {
            return;
        }
        this.#urban = urban;
        this.#ground.material.color.set(urban ? Lawn.URBAN_COLOUR : Lawn.COLOUR);
        this.#paint(this.#ground.geometry);
    }

    /**
     * The ground of a real site: the lawn becomes a grid on the terrain's fine nodes (so the
     * draped layers above it follow the same surface), shaded by its slopes.
     */
    setTerrain(terrain) {
        this.#terrain = terrain;
        const geometry = this.#groundGeometry();
        this.#ground.geometry.dispose();
        this.#ground.geometry = geometry;
        this.#rebuildTufts();
    }

    /**
     * @param {{minX: number, maxX: number, minZ: number, maxZ: number}[]} footprints areas without grass
     * @param {THREE.Vector2} centre where the product stands; tufts grow around it
     */
    setFootprints(footprints, centre) {
        const unchanged = centre.equals(this.#centre)
            && JSON.stringify(footprints) === JSON.stringify(this.#footprints);
        if (unchanged) {
            return;
        }
        this.#footprints = footprints;
        this.#centre.copy(centre);
        this.#rebuildTufts();
    }

    dispose() {
        this.#ground.geometry.dispose();
        this.#ground.material.dispose();
        this.#removeTufts();
    }

    #groundGeometry() {
        const grid = this.#terrain.fine;
        let geometry;
        if (this.#terrain.isFlat || !grid) {
            geometry = new THREE.RingGeometry(0, Lawn.RADIUS, Lawn.SEGMENTS, Lawn.RINGS).rotateX(-Math.PI / 2);
        } else {
            // the fine grid's own triangles: the surface the draped layers are cut to
            const kept = this.#terrain.fineTriangles(Lawn.RADIUS);
            geometry = new THREE.BufferGeometry();
            geometry.setAttribute('position', new THREE.Float32BufferAttribute(kept, 3));
            geometry.setAttribute('uv', new THREE.Float32BufferAttribute(new Float32Array((kept.length / 3) * 2), 2));
            geometry.computeVertexNormals();
        }
        applyMetricUVs(geometry, Lawn.TEXTURE_SIZE);
        this.#paint(geometry);

        return geometry;
    }

    #createGround() {
        const geometry = this.#groundGeometry();

        const texture = proceduralTexture(LAWN);
        const ground = new THREE.Mesh(geometry, new THREE.MeshStandardMaterial({
            color: Lawn.COLOUR,
            vertexColors: true,
            roughness: 1,
            map: texture.map,
            bumpMap: texture.bumpMap,
            bumpScale: texture.bumpScale,
            polygonOffset: true,
            polygonOffsetFactor: LAWN_OFFSET,
            polygonOffsetUnits: LAWN_OFFSET,
        }));
        ground.position.y = -0.002;
        ground.receiveShadow = true;
        ground.name = 'lawn';

        return ground;
    }

    /**
     * Vertex colours: a garden has mown stripes and patches near the house, plain meadow
     * further out; city grass has no stripes but broad worn, drier and darker patches.
     */
    #paint(geometry) {
        const position = geometry.attributes.position;
        const colours = new Float32Array(position.count * 3);
        const colour = new THREE.Color();
        for (let i = 0; i < position.count; i++) {
            const x = position.getX(i);
            const z = position.getZ(i);
            const patches = Math.sin(x * 0.21 + Math.sin(z * 0.13) * 2) * Math.sin(z * 0.17 + Math.cos(x * 0.11) * 1.5);
            if (this.#urban) {
                const wear = Math.sin(x * 0.045 + Math.sin(z * 0.06) * 2.2) * Math.sin(z * 0.05 + Math.cos(x * 0.04) * 1.8);
                colour.setRGB(1, 1, 1).offsetHSL(0.015 * wear + 0.01 * patches, -0.12 * Math.max(0, wear), 0.05 * wear + 0.03 * patches);
            } else {
                const stripe = Math.sin((x / Lawn.STRIPE_WIDTH) * Math.PI) > 0 ? 1 : -1;
                const r = Math.hypot(x, z);
                const garden = THREE.MathUtils.smoothstep(32 - r, 0, 8);
                colour.setRGB(1, 1, 1)
                    .offsetHSL(0.01 * patches, 0.04 * patches * garden, 0.04 * patches * garden + 0.025 * stripe * garden);
            }
            colours.set([colour.r, colour.g, colour.b], i * 3);
        }
        geometry.setAttribute('color', new THREE.BufferAttribute(colours, 3));
    }

    #rebuildTufts() {
        this.#removeTufts();
        const count = Lawn.TUFTS[this.#detail] ?? 0;
        if (count === 0) {
            return;
        }
        const geometry = tuftGeometry();
        const material = new THREE.MeshStandardMaterial({ vertexColors: true, roughness: 0.95 });
        const tufts = new THREE.InstancedMesh(geometry, material, count);
        const matrix = new THREE.Matrix4();
        const quaternion = new THREE.Quaternion();
        const up = new THREE.Vector3(0, 1, 0);
        const random = seededRandom(91);
        let placed = 0;
        for (let attempt = 0; placed < count && attempt < count * 4; attempt++) {
            const angle = random() * Math.PI * 2;
            // denser near the product, thinning towards the edge
            const r = Math.sqrt(random()) * Lawn.TUFT_RADIUS * (0.5 + random() * 0.5);
            const x = this.#centre.x + Math.cos(angle) * r;
            const z = this.#centre.y + Math.sin(angle) * r;
            if (this.#footprints.some((f) => x > f.minX && x < f.maxX && z > f.minZ && z < f.maxZ)) {
                continue;
            }
            // tufts shrink towards the edge of the patch, so it has no visible border
            const fade = 1 - THREE.MathUtils.smoothstep(r, Lawn.TUFT_RADIUS * 0.45, Lawn.TUFT_RADIUS);
            if (fade < 0.15) {
                continue;
            }
            const scale = (0.7 + random() * 0.6) * fade;
            quaternion.setFromAxisAngle(up, random() * Math.PI);
            matrix.compose(new THREE.Vector3(x, this.#terrain.heightAt(x, z), z), quaternion, new THREE.Vector3(scale, scale * (0.8 + random() * 0.5), scale));
            tufts.setMatrixAt(placed++, matrix);
        }
        tufts.count = placed;
        tufts.receiveShadow = true;
        tufts.castShadow = false;
        tufts.name = 'grass';
        this.#tufts = tufts;
        this.#scene.add(tufts);
    }

    #removeTufts() {
        if (this.#tufts) {
            this.#tufts.removeFromParent();
            this.#tufts.geometry.dispose();
            this.#tufts.material.dispose();
            this.#tufts = null;
        }
    }
}

/**
 * Five slim blades (1–1.4 cm wide, 4–9 cm tall) fanning out of one root,
 * darker at the base: 30 triangles. Every face exists in both windings with
 * the material front-sided and the normals pointing up, so a blade shades
 * like the lawn under it from either side: with DoubleSide three flips the
 * normal of a back face, which then points into the ground and renders black.
 */
function tuftGeometry() {
    const positions = [];
    const colours = [];
    const base = new THREE.Color(TUFT_BASE);
    const tip = new THREE.Color(TUFT_TIP);
    const random = seededRandom(17);
    for (let blade = 0; blade < TUFT_BLADES; blade++) {
        const angle = (blade / TUFT_BLADES) * Math.PI * 2 + random() * 0.8;
        const height = 0.04 + random() * 0.05;
        const width = 0.005 + random() * 0.002;
        const lean = height * (0.2 + random() * 0.35);
        const dx = Math.cos(angle);
        const dz = Math.sin(angle);
        // a tapered strip leaning away from the root: two segments and a tip
        const points = [
            [-width, 0, 0], [width, 0, 0],
            [-width * 0.65, height * 0.55, lean * 0.35], [width * 0.65, height * 0.55, lean * 0.35],
            [0, height, lean],
        ];
        const world = points.map(([u, y, w]) => [u * -dz + w * dx, y, u * dx + w * dz]);
        const shade = (y) => base.clone().lerp(tip, y / height);
        for (const [a, b, c] of [[0, 1, 2], [1, 3, 2], [2, 3, 4], [0, 2, 1], [1, 2, 3], [2, 4, 3]]) {
            for (const index of [a, b, c]) {
                positions.push(...world[index]);
                const colour = shade(world[index][1]);
                colours.push(colour.r, colour.g, colour.b);
            }
        }
    }
    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
    geometry.setAttribute('color', new THREE.Float32BufferAttribute(colours, 3));
    // normals pointing up: the tufts shade like the lawn they stand on, not like thin walls
    const normals = new Float32Array(positions.length);
    for (let i = 1; i < normals.length; i += 3) {
        normals[i] = 1;
    }
    geometry.setAttribute('normal', new THREE.BufferAttribute(normals, 3));

    return geometry;
}

function seededRandom(seed) {
    return () => {
        seed |= 0;
        seed = (seed + 0x6d2b79f5) | 0;
        let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;

        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}
