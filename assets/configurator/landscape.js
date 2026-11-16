import * as THREE from 'three';
import { mergeVertices } from 'three/examples/jsm/utils/BufferGeometryUtils.js';
import { CityBlocks, fadeNearCamera, skipOverridePasses } from './city_blocks.js';
import { RealCity } from './real_city.js';
import { TerrainField } from './terrain.js';

/**
 * Low-poly countryside around the garden: rolling hills that start where the
 * lawn ends, a mountain range on the horizon and forests on the hills. Flat
 * shaded with vertex colours, so the whole backdrop costs a handful of draw
 * calls. Everything is seeded: the same landscape on every load.
 *
 * The mountains are beyond the fog; they carry their own atmospheric haze in
 * their vertex colours (fading into the horizon colour with distance).
 *
 * Urban (setUrban, for an aerial view of a real site): flat ground, no mountains,
 * the real buildings and streets around the site once they are loaded (RealCity,
 * setSurroundings), a generic city (CityBlocks) beyond them, and broadleaf street
 * trees instead of forests, all fading into the haze.
 */
export class Landscape {
    /** the site reaches 100 m from its centre (Site\\SiteObject::REACH): the hills rise beyond it */
    static HILLS_INNER = 105;
    static HILLS_OUTER = 420;
    static MOUNTAINS_INNER = 700;
    static MOUNTAINS_OUTER = 1300;
    /** trees per quality detail level (0–3) */
    static TREES = [120, 320, 700, 1300];
    static CLEARING = 100; // no trees closer than this to the garden

    /** urban: no generic blocks within the imported site's radius (ImportSite::DEFAULT_RADIUS_METRES) */
    static CITY_INNER = 82;
    static CITY_GROUND_OUTER = 1400;
    /** street trees along the city's streets, per quality detail level */
    static STREET_TREES = [150, 400, 800, 1200];

    #group = new THREE.Group();
    #trees = null;
    #noise = new ValueNoise(7);
    #hazeColour;
    #urban = false;
    #detail = 1;
    #city = null;
    #lightLevel = 0;
    #clear = [];
    #stale = false;
    #suburban = false;
    /** the real buildings and streets around the site (RealCity), once loaded */
    #surroundings = null;
    #realCity = null;
    /** the real ground of an aerial site (flat until the plan's terrain is loaded) */
    #terrain = TerrainField.FLAT;

    constructor(scene, hazeColour) {
        this.#hazeColour = new THREE.Color(hazeColour);
        this.#group.name = 'landscape';
        this.#group.add(this.#createHills(), this.#createMountains());
        scene.add(this.#group);
    }

    /**
     * Switches between countryside (a garden) and a city (an aerial view of a real site).
     * Rebuilds the backdrop; call once when the scene says which.
     */
    setUrban(urban, suburban = false) {
        if (urban === this.#urban && suburban === this.#suburban) {
            return;
        }
        this.#urban = urban;
        this.#suburban = suburban;
        for (const child of [...this.#group.children]) {
            this.#group.remove(child);
            child.traverse((object) => {
                if (object.isMesh) {
                    object.geometry.dispose();
                    object.material.dispose();
                }
            });
        }
        this.#city = null;
        this.#realCity = null;
        this.#trees = null;
        this.#group.add(this.#createHills());
        if (!urban) {
            this.#group.add(this.#createMountains());
        }
        this.setDetail(this.#detail);
    }

    /**
     * The site's own objects (buildings, roads; rectangles in metres): the city's
     * generic blocks and street trees keep clear of them.
     */
    keepClear(areas) {
        if (JSON.stringify(areas) === JSON.stringify(this.#clear)) {
            return;
        }
        this.#clear = areas;
        if (this.#urban) {
            this.#stale = true; // rebuild the city around the new areas
            this.setDetail(this.#detail);
        }
    }

    /**
     * The real buildings and streets around the site ({radius, buildings, roads}, metres):
     * drawn instead of the generic city within their radius.
     */
    setSurroundings(surroundings) {
        const real = (surroundings?.buildings?.length ?? 0) + (surroundings?.roads?.length ?? 0) > 0;
        this.#surroundings = real ? surroundings : null;
        this.#realCity?.dispose();
        this.#realCity = null;
        if (this.#urban) {
            this.#stale = true;
            this.setDetail(this.#detail);
        }
    }

    /** The real ground of an aerial site: the city's ground, buildings, streets and trees follow it. */
    setTerrain(terrain) {
        this.#terrain = terrain;
        this.#realCity?.dispose();
        this.#realCity = null;
        if (this.#urban) {
            const hills = this.#group.getObjectByName('hills');
            if (hills) {
                this.#group.remove(hills);
                hills.geometry.dispose();
                hills.material.dispose();
            }
            this.#group.add(this.#createHills());
            this.#stale = true;
            this.setDetail(this.#detail);
        }
    }

    /** Evening light in the city's windows (0 by day, 1 at night). */
    setLightLevel(level) {
        this.#lightLevel = level;
        this.#city?.setLightLevel(level);
        this.#realCity?.setLightLevel(level);
    }

    /** Ground height of the hills at (x, z), 0 inside the garden; a city has its real terrain (or none). */
    heightAt(x, z) {
        if (this.#urban) {
            return this.#terrain.heightAt(x, z);
        }
        const r = Math.hypot(x, z);
        const rise = THREE.MathUtils.smoothstep(r, Landscape.HILLS_INNER, Landscape.HILLS_INNER + 160);
        const rolling = this.#noise.fbm(x * 0.009, z * 0.009, 4) * 20 + this.#noise.fbm(x * 0.035 + 9, z * 0.035, 2) * 3;
        const lift = THREE.MathUtils.smoothstep(r, 200, Landscape.HILLS_OUTER) * 26;

        return rise * (Math.max(0, rolling + 6) + lift);
    }

    /** Tints the unlit mountains for a mood (white = as baked). */
    setTint(colour) {
        this.#group.getObjectByName('mountains')?.material.color.set(colour);
    }

    setDetail(detail) {
        this.#detail = detail;
        if (this.#trees?.userData.detail === detail && !this.#stale) {
            return;
        }
        this.#stale = false;
        if (this.#trees?.parent) {
            this.#group.remove(this.#trees);
            this.#trees.traverse((object) => object.isMesh && object.geometry.dispose());
        }
        this.#city?.dispose();
        this.#city = null;
        if (this.#urban) {
            // the real city where the map has it, the generic one only beyond
            if (this.#surroundings && !this.#realCity) {
                this.#realCity = new RealCity(this.#surroundings, this.#terrain);
                this.#realCity.setLightLevel(this.#lightLevel);
                this.#group.add(this.#realCity.object);
            }
            const inner = Math.max(Landscape.CITY_INNER, this.#surroundings?.radius ?? 0);
            this.#city = new CityBlocks(inner, detail, this.#clear, this.#suburban, (x, z) => this.#terrain.heightAt(x, z));
            this.#city.setLightLevel(this.#lightLevel);
            this.#group.add(this.#city.object);
            this.#trees = this.#createStreetTrees(Landscape.STREET_TREES[detail] ?? Landscape.STREET_TREES[1], this.#city.streets, this.#realCity?.trees ?? []);
        } else {
            this.#trees = this.#createForest(Landscape.TREES[detail] ?? Landscape.TREES[1]);
        }
        this.#trees.userData.detail = detail;
        this.#group.add(this.#trees);
    }

    dispose() {
        this.#group.traverse((object) => {
            if (object.isMesh) {
                object.geometry.dispose();
                object.material.dispose();
            }
        });
        this.#group.removeFromParent();
    }

    #createHills() {
        // a city's ground reaches out under the farthest blocks, into the haze
        const outer = this.#urban ? Landscape.CITY_GROUND_OUTER : Landscape.HILLS_OUTER;
        const geometry = polarGrid(Landscape.HILLS_INNER - 6, outer, 140, 48, 1.6);
        const position = geometry.attributes.position;
        const colours = [];
        // the inner edge takes the lawn's apparent colour, so the two meet without a seam;
        // a city's ground is mostly paved, with green courtyards and parks between
        const urban = this.#urban;
        const lawnTone = new THREE.Color(urban ? 0x5a7340 : 0x4f8230);
        const meadow = new THREE.Color(urban ? 0x6b7a52 : 0x60803f);
        const dryMeadow = new THREE.Color(urban ? 0x85837a : 0x8a9a52);
        const field = new THREE.Color(urban ? 0x7a7a76 : 0xa99f62);
        const colour = new THREE.Color();
        for (let i = 0; i < position.count; i++) {
            const x = position.getX(i);
            const z = position.getZ(i);
            // below the lawn by more where the real terrain bends: the coarse city ground must not poke through it
            position.setY(i, this.heightAt(x, z) - (this.#terrain.isFlat ? 0.02 : 0.08));
            // patches: meadows, drier grass and the odd crop field
            const patch = this.#noise.fbm(x * 0.012 + 40, z * 0.012 - 13, 3);
            colour.copy(meadow).lerp(dryMeadow, THREE.MathUtils.clamp(patch + 0.25, 0, 0.8));
            if (this.#noise.fbm(x * 0.006 - 7, z * 0.006 + 3, 2) > 0.3) {
                colour.lerp(field, 0.5);
            }
            const r = Math.hypot(x, z);
            colour.lerp(lawnTone, 1 - THREE.MathUtils.smoothstep(r, Landscape.HILLS_INNER, Landscape.HILLS_INNER + 70));
            colours.push(colour.r, colour.g, colour.b);
        }
        geometry.setAttribute('color', new THREE.Float32BufferAttribute(colours, 3));
        const flat = geometry.toNonIndexed();
        flat.computeVertexNormals();
        geometry.dispose();

        const hills = new THREE.Mesh(flat, new THREE.MeshStandardMaterial({ vertexColors: true, roughness: 1, flatShading: true }));
        hills.receiveShadow = false;
        hills.name = 'hills';

        return hills;
    }

    #createMountains() {
        const geometry = polarGrid(Landscape.MOUNTAINS_INNER, Landscape.MOUNTAINS_OUTER, 260, 26, 1);
        const position = geometry.attributes.position;
        const colours = [];
        const rock = new THREE.Color(0x6d7480);
        const forest = new THREE.Color(0x46573f);
        const snow = new THREE.Color(0xf4f6fa);
        const colour = new THREE.Color();
        for (let i = 0; i < position.count; i++) {
            const x = position.getX(i);
            const z = position.getZ(i);
            const r = Math.hypot(x, z);
            const along = THREE.MathUtils.smoothstep(r, Landscape.MOUNTAINS_INNER, Landscape.MOUNTAINS_INNER + 140);
            const ridge = 1 - Math.abs(this.#noise.fbm(x * 0.004, z * 0.004, 4));
            const height = along * (ridge ** 2.2 * 170 + this.#noise.fbm(x * 0.02, z * 0.02, 2) * 14);
            position.setY(i, height);

            colour.copy(forest).lerp(rock, THREE.MathUtils.smoothstep(height, 30, 85));
            colour.lerp(snow, THREE.MathUtils.smoothstep(height, 110, 140));
            // aerial perspective: distant slopes fade into the haze
            colour.lerp(this.#hazeColour, 0.45 + 0.35 * THREE.MathUtils.smoothstep(r, Landscape.MOUNTAINS_INNER, Landscape.MOUNTAINS_OUTER));
            colours.push(colour.r, colour.g, colour.b);
        }
        geometry.computeVertexNormals();
        // distant mountains are mostly haze: bake a gentle light from above into the
        // colours and draw them unlit, so they read the same from every direction
        const normal = geometry.attributes.normal;
        for (let i = 0; i < position.count; i++) {
            const light = 0.82 + 0.28 * Math.max(0, normal.getY(i) * 0.6 + normal.getX(i) * 0.3 + normal.getZ(i) * 0.3);
            colours[i * 3] *= light;
            colours[i * 3 + 1] *= light;
            colours[i * 3 + 2] *= light;
        }
        geometry.setAttribute('color', new THREE.Float32BufferAttribute(colours, 3));

        const mountains = new THREE.Mesh(geometry, new THREE.MeshBasicMaterial({ vertexColors: true, fog: false }));
        mountains.name = 'mountains';

        return mountains;
    }

    /** Broadleaf trees along the city's streets (both sides), outside the site's square. */
    /** @param {object[]} real trees along the real streets (RealCity), planted first */
    #createStreetTrees(count, { cell, street, inner, outer }, real = []) {
        const random = seededRandom(29);
        const trees = [...real];
        count += real.length;
        let attempts = 0;
        while (trees.length < count && attempts++ < count * 20) {
            // a point on a street: one coordinate on a street line, the other anywhere
            const line = Math.round((random() * 2 - 1) * (outer / cell)) * cell;
            const side = (random() < 0.5 ? -1 : 1) * (street / 2 - 1.5);
            const along = (random() * 2 - 1) * outer;
            const [x, z] = random() < 0.5 ? [line + side, along] : [along, line + side];
            const onSite = this.#clear.some((area) => x > area.minX - 2 && x < area.maxX + 2 && z > area.minZ - 2 && z < area.maxZ + 2);
            if (Math.hypot(x, z) < inner || Math.hypot(x, z) > outer || onSite) {
                continue;
            }
            trees.push({ x, z, y: this.heightAt(x, z), scale: 0.8 + random() * 0.5, turn: random() * Math.PI * 2, tint: random() });
        }
        const group = new THREE.Group();
        group.name = 'street_trees';
        group.add(...instanced(broadleafCrown(), 0x4a6534, trees, 0.16, false), ...instanced(trunk(2.2), 0x5f4935, trees, 0));
        group.traverse((object) => {
            if (object.isMesh) {
                fadeNearCamera(object.material);
                skipOverridePasses(object);
            }
        });

        return group;
    }

    /** Conifers and broadleaf trees, instanced, on the hills outside the clearing. */
    #createForest(count) {
        const random = seededRandom(23);
        const conifers = [];
        const broadleaves = [];
        let attempts = 0;
        while (conifers.length + broadleaves.length < count && attempts++ < count * 20) {
            const angle = random() * Math.PI * 2;
            const r = Landscape.CLEARING + random() ** 1.3 * (Landscape.HILLS_OUTER - 60 - Landscape.CLEARING);
            const x = Math.cos(angle) * r;
            const z = Math.sin(angle) * r;
            // forests grow in patches: keep a tree only where the forest noise says so
            const density = this.#noise.fbm(x * 0.015 + 100, z * 0.015, 3);
            if (density < 0.05 && r > 60) {
                continue;
            }
            const scale = 0.6 + random() * 0.6 + r / 300;
            const tree = { x, z, y: this.heightAt(x, z), scale, turn: random() * Math.PI * 2, tint: random() };
            (this.#noise.fbm(x * 0.01 - 50, z * 0.01, 2) > 0 ? conifers : broadleaves).push(tree);
        }

        const group = new THREE.Group();
        group.name = 'forest';
        group.add(
            ...instanced(coniferCrown(), 0x3d5544, conifers, 0.1),
            ...instanced(trunk(1.6), 0x5a4330, conifers, 0),
            ...instanced(broadleafCrown(), 0x4a6534, broadleaves, 0.16, false),
            ...instanced(trunk(2.2), 0x5f4935, broadleaves, 0),
        );

        return group;
    }
}

function coniferCrown() {
    const tiers = [
        new THREE.ConeGeometry(2.2, 4.5, 7).translate(0, 3.6, 0),
        new THREE.ConeGeometry(1.7, 3.8, 7).translate(0, 5.6, 0),
        new THREE.ConeGeometry(1.1, 3.0, 7).translate(0, 7.4, 0),
    ];
    const merged = mergeSimple(tiers);
    tiers.forEach((tier) => tier.dispose());

    return merged;
}

function broadleafCrown() {
    const crown = new THREE.IcosahedronGeometry(2.6, 2);
    const position = crown.attributes.position;
    // jitter derived from the position, so vertices shared by several faces move together
    const lumpiness = (x, y, z) => 0.88 + 0.24 * (0.5 + 0.5 * Math.sin(x * 3.1 + Math.sin(z * 2.3) * 2 + y * 1.7));
    for (let i = 0; i < position.count; i++) {
        const [x, y, z] = [position.getX(i), position.getY(i), position.getZ(i)];
        const k = lumpiness(x, y, z);
        position.setXYZ(i, x * k, y * k * 0.85 + 4.6, z * k);
    }
    crown.deleteAttribute('normal');
    crown.deleteAttribute('uv');
    const smooth = mergeVertices(crown);
    crown.dispose();
    smooth.computeVertexNormals();

    return smooth;
}

function trunk(height) {
    return new THREE.CylinderGeometry(0.18, 0.28, height, 5).translate(0, height / 2, 0).toNonIndexed(); // cylinders are indexed
}

/** One InstancedMesh per geometry; `tint` varies each instance's colour a little. */
function instanced(geometry, colour, trees, tint, flat = true) {
    if (trees.length === 0) {
        return [];
    }
    geometry.computeVertexNormals();
    // instance colours multiply the material colour: keep it white when tinting per instance
    const material = new THREE.MeshStandardMaterial({ color: tint > 0 ? 0xffffff : colour, roughness: 0.95, flatShading: flat });
    const mesh = new THREE.InstancedMesh(geometry, material, trees.length);
    const matrix = new THREE.Matrix4();
    const quaternion = new THREE.Quaternion();
    const base = new THREE.Color(colour);
    const variant = new THREE.Color();
    trees.forEach((tree, i) => {
        quaternion.setFromAxisAngle(new THREE.Vector3(0, 1, 0), tree.turn);
        matrix.compose(new THREE.Vector3(tree.x, tree.y - 0.3, tree.z), quaternion, new THREE.Vector3(tree.scale, tree.scale * (0.9 + tree.tint * 0.3), tree.scale));
        mesh.setMatrixAt(i, matrix);
        if (tint > 0) {
            variant.copy(base).offsetHSL((tree.tint - 0.5) * 0.04, 0, (tree.tint - 0.5) * tint);
            mesh.setColorAt(i, variant);
        }
    });
    mesh.castShadow = false;
    mesh.receiveShadow = false;

    return [mesh];
}

/**
 * A ring of quads in polar coordinates, denser near the inner edge
 * (`falloff` > 1), lying in the x/z plane.
 */
function polarGrid(inner, outer, segments, rings, falloff) {
    const positions = [];
    const indices = [];
    for (let ring = 0; ring <= rings; ring++) {
        const r = inner + (outer - inner) * (ring / rings) ** falloff;
        for (let segment = 0; segment <= segments; segment++) {
            const angle = (segment / segments) * Math.PI * 2;
            positions.push(Math.cos(angle) * r, 0, Math.sin(angle) * r);
        }
    }
    const row = segments + 1;
    for (let ring = 0; ring < rings; ring++) {
        for (let segment = 0; segment < segments; segment++) {
            const a = ring * row + segment;
            const b = a + row;
            indices.push(a, a + 1, b, b, a + 1, b + 1);
        }
    }
    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
    geometry.setIndex(indices);

    return geometry;
}

function mergeSimple(geometries) {
    const parts = geometries.map((geometry) => (geometry.index ? geometry.toNonIndexed() : geometry.clone()));
    const total = parts.reduce((sum, part) => sum + part.attributes.position.count, 0);
    const positions = new Float32Array(total * 3);
    let offset = 0;
    for (const part of parts) {
        positions.set(part.attributes.position.array, offset);
        offset += part.attributes.position.array.length;
        part.dispose();
    }
    const merged = new THREE.BufferGeometry();
    merged.setAttribute('position', new THREE.BufferAttribute(positions, 3));

    return merged;
}

/** Smooth 2D value noise with octaves, roughly in -1…1. */
class ValueNoise {
    #seed;

    constructor(seed) {
        this.#seed = seed;
    }

    fbm(x, y, octaves) {
        let sum = 0;
        let amplitude = 0.5;
        let frequency = 1;
        for (let i = 0; i < octaves; i++) {
            sum += amplitude * this.#noise(x * frequency, y * frequency);
            amplitude *= 0.5;
            frequency *= 2.03;
        }

        return sum * 2;
    }

    #noise(x, y) {
        const xi = Math.floor(x);
        const yi = Math.floor(y);
        const xf = x - xi;
        const yf = y - yi;
        const u = xf * xf * (3 - 2 * xf);
        const v = yf * yf * (3 - 2 * yf);
        const a = this.#hash(xi, yi);
        const b = this.#hash(xi + 1, yi);
        const c = this.#hash(xi, yi + 1);
        const d = this.#hash(xi + 1, yi + 1);

        return THREE.MathUtils.lerp(THREE.MathUtils.lerp(a, b, u), THREE.MathUtils.lerp(c, d, u), v) - 0.5;
    }

    #hash(x, y) {
        let h = Math.imul(x, 374761393) ^ Math.imul(y, 668265263) ^ Math.imul(this.#seed, 2147483647);
        h = Math.imul(h ^ (h >>> 13), 1274126177);

        return ((h ^ (h >>> 16)) >>> 0) / 4294967296;
    }
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
