import * as THREE from 'three';
import { RoundedBoxGeometry } from 'three/examples/jsm/geometries/RoundedBoxGeometry.js';
import { mergeGeometries, mergeVertices } from 'three/examples/jsm/utils/BufferGeometryUtils.js';
import { FOLIAGE, PLASTER, applyMetricUVs, proceduralTexture } from './procedural_textures.js';
import { ModelLibrary } from './model_library.js';

/**
 * The garden around the product, in the convention of the House scenery
 * (façade at z = 0 facing +z): a hedge around the plot, a few garden trees,
 * stepping stones from the product into the garden and neighbouring houses
 * beyond the hedge. Rebuilt when the product's footprint changes (layout()).
 * Nothing here casts a shadow: the sun's shadow map stays sharp for the product.
 *
 * With detail 3 (ultra) and a tree model library (`treeUrl`, CC0 jacaranda,
 * variant tree_01), the drawn trees are replaced by the model, loaded in the
 * background; `onChange` asks for a new frame once it is there.
 */
export class Garden {
    static PLOT_HALF_WIDTH = 17;
    static PLOT_BACK = -10;
    static PLOT_FRONT = 24;
    static HEDGE_HEIGHT = 1.7;
    static HEDGE_DEPTH = 0.9;
    static STONE = 0.55;

    static TREE_VARIANT = 'tree_01';
    /** heights of the model trees, in the order of TREES */
    static MODEL_TREE_HEIGHTS = [7.5, 6, 8.5];
    static TREES = [[-11, 15, 1.15], [11.5, 9, 0.95], [-13, -6, 1.3]];
    /** a garden tree keeps this far (metres) from what stands in the areas kept clear */
    static TREE_CLEARANCE = 2.5;

    #group = new THREE.Group();
    #trees = new THREE.Group();
    #materials;
    #key = null;
    #treeUrl;
    #onChange;
    #modelTrees = false;
    #models = new ModelLibrary();
    #treeGeneration = 0;
    /** outlines of stones and hedges, for the lawn to keep its grass off */
    footprints = [];
    /** areas the garden leaves free: [{minX, maxX, minZ, maxZ}] in metres (keepClear) */
    #clearAreas = [];
    #lastLayout = null;

    constructor(scene, { treeUrl = null, onChange = () => {} } = {}) {
        this.#group.name = 'garden';
        this.#trees.name = 'garden-trees';
        this.#treeUrl = treeUrl;
        this.#onChange = onChange;
        this.#materials = {
            hedge: new THREE.MeshStandardMaterial({ color: 0x3f5a2e, roughness: 1, ...foliage() }),
            stone: new THREE.MeshStandardMaterial({ color: 0x8f8a80, roughness: 0.95, ...stoneSurface() }),
            bark: new THREE.MeshStandardMaterial({ color: 0x5e4b3a, roughness: 1 }),
            // crowns are round: a planar texture would smear, so their bumps carry the shading (vertex colours)
            crown: new THREE.MeshStandardMaterial({ color: 0x55743a, roughness: 0.95, vertexColors: true }),
            crownAutumn: new THREE.MeshStandardMaterial({ color: 0x7d8a3c, roughness: 0.95, vertexColors: true }),
        };
        scene.add(this.#group, this.#trees);
    }

    /** Model trees from detail 3 on, drawn ones below. */
    setDetail(detail) {
        const modelTrees = detail >= 3 && Boolean(this.#treeUrl);
        if (modelTrees !== this.#modelTrees) {
            this.#modelTrees = modelTrees;
            this.#addTrees();
        }
    }

    /**
     * @param {THREE.Box3} productBox the product's bounds; the path starts at its front
     * @param {{path?: boolean}} options path: stepping stones from the product into the garden
     *        (off for products at the edge of the plot, like a fence)
     */
    layout(productBox, { path = true } = {}) {
        this.#lastLayout = { productBox: productBox.clone(), path };
        const front = productBox.isEmpty() ? 4 : productBox.max.z;
        const centreX = productBox.isEmpty() ? 0 : (productBox.min.x + productBox.max.x) / 2;
        const key = `${front.toFixed(1)}|${centreX.toFixed(1)}|${path}|${this.#clearKey()}`;
        if (key === this.#key) {
            return;
        }
        this.#key = key;
        this.#clear();
        this.footprints = [];
        this.#addHedges();
        if (path) {
            this.#addPath(centreX, front + 0.9);
        }
        if (this.#trees.children.length === 0) {
            this.#addTrees();
        }
        this.#applyClearToTrees();
        for (const [x, z] of Garden.TREES.filter(([x, z]) => this.#isFree(x, z, Garden.TREE_CLEARANCE))) {
            this.footprints.push({ minX: x - 0.5, maxX: x + 0.5, minZ: z - 0.5, maxZ: z + 0.5 });
        }
        this.#addNeighbours();
    }

    /**
     * Leaves these areas free of hedges, trees and neighbouring houses (the
     * customer's own house, garage, paving, …) and lays the garden out again.
     *
     * @param {{minX: number, maxX: number, minZ: number, maxZ: number}[]} areas metres
     */
    keepClear(areas) {
        const before = this.#clearKey();
        this.#clearAreas = areas;
        if (this.#clearKey() !== before && this.#lastLayout) {
            this.layout(this.#lastLayout.productBox, { path: this.#lastLayout.path });
        }
    }

    #clearKey() {
        return this.#clearAreas.map((a) => `${a.minX.toFixed(1)},${a.maxX.toFixed(1)},${a.minZ.toFixed(1)},${a.maxZ.toFixed(1)}`).join(';');
    }

    /** Whether a point stands at least `margin` metres away from every area kept clear. */
    #isFree(x, z, margin = 0) {
        return !this.#clearAreas.some((a) => x > a.minX - margin && x < a.maxX + margin && z > a.minZ - margin && z < a.maxZ + margin);
    }

    #applyClearToTrees() {
        this.#trees.children.forEach((tree) => { tree.visible = this.#isFree(tree.position.x, tree.position.z, Garden.TREE_CLEARANCE); });
    }

    dispose() {
        this.#clear();
        this.#clearTrees();
        this.#trees.removeFromParent();
        Object.values(this.#materials).forEach((material) => material.dispose());
        this.#group.removeFromParent();
    }

    #clear() {
        this.#group.traverse((object) => object.isMesh && object.geometry.dispose());
        this.#group.clear();
    }

    /** Sides and back of the plot, with a gap at the back right; merged into one mesh. */
    #addHedges() {
        const w = Garden.PLOT_HALF_WIDTH;
        const runs = [
            [-w, Garden.PLOT_BACK, -w, Garden.PLOT_FRONT],
            [w, Garden.PLOT_BACK, w, Garden.PLOT_FRONT],
            [-w, Garden.PLOT_FRONT, w - 6, Garden.PLOT_FRONT],
        ];
        const pieces = [];
        for (const [x1, z1, x2, z2] of runs) {
            const length = Math.hypot(x2 - x1, z2 - z1);
            const segments = Math.ceil(length / 2.4);
            for (let i = 0; i < segments; i++) {
                const t = (i + 0.5) / segments;
                const x = x1 + (x2 - x1) * t;
                const z = z1 + (z2 - z1) * t;
                if (!this.#isFree(x, z, length / segments / 2)) {
                    continue;
                }
                const alongX = Math.abs(x2 - x1) > Math.abs(z2 - z1);
                const height = Garden.HEDGE_HEIGHT * (0.92 + 0.16 * Math.sin(i * 1.7 + x));
                const piece = lumpy(new RoundedBoxGeometry(alongX ? length / segments + 0.25 : Garden.HEDGE_DEPTH, height, alongX ? Garden.HEDGE_DEPTH : length / segments + 0.25, 3, 0.35), 0.09, i);
                pieces.push(piece.translate(x, height / 2, z));
                const half = alongX ? [length / segments / 2, Garden.HEDGE_DEPTH / 2] : [Garden.HEDGE_DEPTH / 2, length / segments / 2];
                this.footprints.push({ minX: x - half[0] - 0.2, maxX: x + half[0] + 0.2, minZ: z - half[1] - 0.2, maxZ: z + half[1] + 0.2 });
            }
        }
        this.#group.add(mergedMesh(pieces, this.#materials.hedge, 1.2));
    }

    /** Stepping stones in a gentle curve from the product's front towards the back of the garden. */
    #addPath(startX, startZ) {
        const curve = new THREE.CatmullRomCurve3([
            new THREE.Vector3(startX + 0.5, 0, startZ),
            new THREE.Vector3(startX + 2.2, 0, startZ + 4),
            new THREE.Vector3(startX + 1.2, 0, startZ + 9),
            new THREE.Vector3(startX + 3.5, 0, Garden.PLOT_FRONT - 2),
        ]);
        const count = Math.floor(curve.getLength() / 0.75);
        const stones = [];
        for (let i = 0; i < count; i++) {
            const point = curve.getPointAt(i / count);
            const turn = Math.sin(i * 2.3) * 0.4;
            const stone = new RoundedBoxGeometry(Garden.STONE * (0.9 + 0.2 * Math.sin(i * 3.1)), 0.05, Garden.STONE * 0.75, 2, 0.02)
                .rotateY(turn)
                .translate(point.x, 0.012, point.z);
            stones.push(stone);
            this.footprints.push({ minX: point.x - 0.4, maxX: point.x + 0.4, minZ: point.z - 0.35, maxZ: point.z + 0.35 });
        }
        this.#group.add(mergedMesh(stones, this.#materials.stone, 0.8));
    }

    #addTrees() {
        const generation = ++this.#treeGeneration;
        if (this.#modelTrees) {
            this.#addModelTrees(generation);

            return;
        }
        this.#clearTrees();
        const crowns = [this.#materials.crown, this.#materials.crownAutumn, this.#materials.crown];
        for (const [i, [x, z, scale]] of Garden.TREES.entries()) {
            const crownMaterial = crowns[i];
            const trunk = new THREE.CylinderGeometry(0.14, 0.24, 3.2, 7).translate(0, 1.6, 0);
            const branches = [0, 1, 2].map((i) => new THREE.CylinderGeometry(0.05, 0.1, 1.6, 5)
                .rotateZ(0.7).rotateY(i * 2.1).translate(0, 2.9, 0));
            const tree = new THREE.Group();
            tree.add(mergedMesh([trunk, ...branches], this.#materials.bark));
            const blobs = [[0, 4.6, 0, 1.9], [1.1, 4.0, 0.5, 1.4], [-1, 4.2, -0.4, 1.5], [0.2, 5.4, -0.6, 1.3], [-0.3, 4.1, 1.0, 1.2]]
                .map(([cx, cy, cz, r], i) => lumpy(new THREE.IcosahedronGeometry(r, 3), 0.12, i * 7 + x).translate(cx, cy, cz));
            tree.add(mergedMesh(blobs, crownMaterial, 1.5));
            tree.position.set(x, 0, z);
            tree.scale.setScalar(scale);
            // model trees share their geometry with the library cache: only drawn ones are disposed
            tree.traverse((object) => { object.userData.drawn = true; });
            this.#trees.add(tree);
        }
        this.#applyClearToTrees();
    }

    /** The drawn trees stay until the models are loaded, so the garden never looks empty. */
    async #addModelTrees(generation) {
        const results = await Promise.allSettled(Garden.TREES.map(([x, z], i) => this.#models
            .variant(this.#treeUrl, Garden.TREE_VARIANT, [0, Garden.MODEL_TREE_HEIGHTS[i], 0])
            .then((tree) => {
                tree.position.set(x, 0, z);
                tree.rotation.y = i * 2.2;

                return tree;
            })));
        if (generation !== this.#treeGeneration) {
            return;
        }
        const trees = results.filter((result) => result.status === 'fulfilled' && result.value).map((result) => result.value);
        if (trees.length === 0) {
            console.warn('Garden: tree model failed to load', results[0]?.reason);
            return;
        }
        this.#clearTrees();
        trees.forEach((tree) => this.#trees.add(tree));
        this.#applyClearToTrees();
        this.#onChange();
    }

    #clearTrees() {
        this.#trees.traverse((object) => object.isMesh && object.userData.drawn && object.geometry.dispose());
        this.#trees.clear();
    }

    /** Houses beyond the hedge: plain bodies, gable roofs and a window grid. */
    #addNeighbours() {
        const houses = [
            { x: -34, z: -4, width: 10, depth: 9, eaves: 5.6, wall: 0xe9e2d6, roof: 0x5b3b33, turn: Math.PI / 2 },
            { x: 33, z: 2, width: 11, depth: 9, eaves: 6, wall: 0xdedcd6, roof: 0x3d3d40, turn: -Math.PI / 2 },
            { x: -18, z: 42, width: 9, depth: 8, eaves: 5.4, wall: 0xf0e6d2, roof: 0x6a4034, turn: Math.PI },
            { x: 16, z: 45, width: 12, depth: 9, eaves: 6.2, wall: 0xd9d4ca, roof: 0x44464a, turn: Math.PI },
        ];
        for (const house of houses.filter(({ x, z, width }) => this.#isFree(x, z, width / 2))) {
            this.#group.add(neighbourHouse(house));
        }
    }
}

function neighbourHouse({ x, z, width, depth, eaves, wall, roof, turn }) {
    const group = new THREE.Group();
    const windows = windowGridTexture(width, eaves);
    const body = new THREE.Mesh(
        new THREE.BoxGeometry(width, eaves, depth).translate(0, eaves / 2, 0),
        [
            new THREE.MeshStandardMaterial({ color: wall, roughness: 0.95 }),
            new THREE.MeshStandardMaterial({ color: wall, roughness: 0.95 }),
            new THREE.MeshStandardMaterial({ color: wall, roughness: 0.95 }),
            new THREE.MeshStandardMaterial({ color: wall, roughness: 0.95 }),
            new THREE.MeshStandardMaterial({ color: 0xffffff, roughness: 0.6, map: windows }),
            new THREE.MeshStandardMaterial({ color: 0xffffff, roughness: 0.6, map: windows }),
        ],
    );
    const ridge = depth / 2 * Math.tan(THREE.MathUtils.degToRad(40));
    const profile = new THREE.Shape([
        new THREE.Vector2(-depth / 2 - 0.5, -0.4),
        new THREE.Vector2(depth / 2 + 0.5, -0.4),
        new THREE.Vector2(0, ridge),
    ]);
    const roofMesh = new THREE.Mesh(
        new THREE.ExtrudeGeometry(profile, { depth: width + 0.6, bevelEnabled: false })
            .translate(0, 0, -(width + 0.6) / 2)
            .rotateY(Math.PI / 2)
            .translate(0, eaves, 0),
        new THREE.MeshStandardMaterial({ color: roof, roughness: 0.8, flatShading: true }),
    );
    group.add(body, roofMesh);
    group.position.set(x, 0, z);
    group.rotation.y = turn;

    return group;
}

/** Façade with two floors of windows, drawn on a canvas sized to the wall. */
function windowGridTexture(width, height) {
    const scale = 40;
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(width * scale);
    canvas.height = Math.round(height * scale);
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#ebe5da';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    const columns = Math.floor(width / 2.6);
    for (const floor of [0.22, 0.62]) {
        for (let i = 0; i < columns; i++) {
            const x = ((i + 0.5) / columns) * canvas.width - 0.6 * scale;
            const y = canvas.height * (1 - floor) - 1.4 * scale;
            ctx.fillStyle = '#3b4248';
            ctx.fillRect(x, y, 1.2 * scale, 1.35 * scale);
            ctx.fillStyle = '#7f8a92';
            ctx.fillRect(x + 4, y + 4, 1.2 * scale - 8, 0.5 * scale);
        }
    }
    const texture = new THREE.CanvasTexture(canvas);
    texture.colorSpace = THREE.SRGBColorSpace;

    return texture;
}

/** Weathered stone: the plaster pattern's grain and blotches. */
function stoneSurface() {
    const texture = proceduralTexture(PLASTER);

    return { map: texture.map, bumpMap: texture.bumpMap, bumpScale: 2 };
}

/** Leafy surface: colour map and bump of the foliage pattern. */
function foliage() {
    const texture = proceduralTexture(FOLIAGE);

    return texture ? { map: texture.map, bumpMap: texture.bumpMap, bumpScale: texture.bumpScale } : {};
}

/** Displaces vertices along their normal with smooth noise: hedge and crown silhouettes. */
function lumpy(geometry, amount, seed) {
    geometry.deleteAttribute('uv');
    const merged = mergeVertices(geometry);
    geometry.dispose();
    merged.computeVertexNormals();
    const position = merged.attributes.position;
    const normal = merged.attributes.normal;
    for (let i = 0; i < position.count; i++) {
        const [x, y, z] = [position.getX(i), position.getY(i), position.getZ(i)];
        const n = Math.sin(x * 4.1 + seed) * Math.sin(y * 3.7 - seed) * Math.sin(z * 4.3 + seed * 0.5)
            + 0.5 * Math.sin(x * 9.3 + y * 7.1 + seed);
        position.setXYZ(i, x + normal.getX(i) * n * amount, y + normal.getY(i) * n * amount, z + normal.getZ(i) * n * amount);
    }
    merged.computeVertexNormals();
    merged.setAttribute('uv', new THREE.BufferAttribute(new Float32Array(position.count * 2), 2));
    // darker in the dips, lighter on the bumps: clumps of leaves
    const colours = new Float32Array(position.count * 3);
    for (let i = 0; i < position.count; i++) {
        const [x, y, z] = [position.getX(i), position.getY(i), position.getZ(i)];
        const n = Math.sin(x * 4.1 + seed) * Math.sin(y * 3.7 - seed) * Math.sin(z * 4.3 + seed * 0.5)
            + 0.5 * Math.sin(x * 9.3 + y * 7.1 + seed);
        const shade = THREE.MathUtils.clamp(0.82 + n * 0.28 + (y > 0 ? 0.06 : 0), 0.45, 1.2);
        colours.set([shade, shade, shade * 0.95], i * 3);
    }
    merged.setAttribute('color', new THREE.BufferAttribute(colours, 3));

    return merged;
}

function mergedMesh(geometries, material, textureSize = 0) {
    if (geometries.length === 0) {
        return new THREE.Group(); // everything was kept clear (a large plot): nothing to merge
    }
    const prepared = geometries.map((geometry) => {
        const flat = geometry.index ? geometry.toNonIndexed() : geometry;
        if (!flat.attributes.uv) {
            flat.setAttribute('uv', new THREE.BufferAttribute(new Float32Array(flat.attributes.position.count * 2), 2));
        }

        return flat;
    });
    const merged = mergeGeometries(prepared, false);
    if (textureSize > 0) {
        applyMetricUVs(merged, textureSize);
    }
    const mesh = new THREE.Mesh(merged, material);
    mesh.receiveShadow = true;

    return mesh;
}
