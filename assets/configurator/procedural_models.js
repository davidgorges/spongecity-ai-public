import * as THREE from 'three';
import { RoundedBoxGeometry } from 'three/examples/jsm/geometries/RoundedBoxGeometry.js';
import { mergeGeometries } from 'three/examples/jsm/utils/BufferGeometryUtils.js';
import { PLANKS, applyMetricUVs, proceduralTexture } from './procedural_textures.js';

/**
 * Model libraries built in the browser instead of loaded from a GLB, for
 * scenery we do not need a download for. The server refers to them with the
 * URL "procedural:<library>" (App\Configurator\AssetMapperModelCatalog);
 * ModelLibrary asks build() for the library and picks variants by name, as
 * with a GLB. Sizes are real-world metres; origins sit on the floor.
 */
export const PROCEDURAL_PREFIX = 'procedural:';

/** candela of the fire pit's light at full night; the renderer scales it by the mood */
const FIRE_LIGHT_INTENSITY = 6;
const SPARKS = 18;
/** edge length of the halo sprite in metres: a hint of heat haze, not a light bulb */
const FIRE_GLOW_SIZE = 1.3;

const LIBRARIES = {
    garden_furniture: buildGardenFurniture,
};

/** @returns {THREE.Group|null} a group with one named child per variant */
export function buildProceduralLibrary(url) {
    const build = LIBRARIES[url.slice(PROCEDURAL_PREFIX.length)];

    return build ? build() : null;
}

function buildGardenFurniture() {
    const m = furnitureMaterials();
    const library = new THREE.Group();
    library.add(
        named('lounge_sofa', mergeByMaterial(loungeSofa(m, 2.2))),
        named('lounge_armchair', mergeByMaterial(loungeSofa(m, 0.85))),
        named('coffee_table', mergeByMaterial(coffeeTable(m))),
        named('rug', rug()),
        named('olive_planter', mergeByMaterial(olivePlanter(m))),
        named('side_table', mergeByMaterial(sideTable(m))),
        named('fire_pit', firePit(m)),
    );
    library.traverse((object) => {
        if (object.isMesh) {
            object.castShadow = !object.userData.noShadow;
            object.receiveShadow = true;
        }
    });

    return library;
}

function furnitureMaterials() {
    const planks = proceduralTexture(PLANKS);

    return {
        frame: new THREE.MeshPhysicalMaterial({ color: 0x34383b, roughness: 0.45, metalness: 0.6, clearcoat: 0.2 }),
        teak: new THREE.MeshPhysicalMaterial({ color: 0xa8774a, roughness: 0.7, map: planks.map, bumpMap: planks.bumpMap, bumpScale: 0.6, userData: { textureSize: 0.6 } }),
        cushion: new THREE.MeshPhysicalMaterial({ color: 0xd9d3c7, roughness: 0.95, sheen: 1, sheenColor: 0xffffff, sheenRoughness: 0.6 }),
        accent: new THREE.MeshPhysicalMaterial({ color: 0x7a8a6a, roughness: 0.95, sheen: 1, sheenColor: 0xdfe8d0, sheenRoughness: 0.5 }),
        accentWarm: new THREE.MeshPhysicalMaterial({ color: 0xc58a5a, roughness: 0.95, sheen: 1, sheenColor: 0xffe2c8, sheenRoughness: 0.5 }),
        planter: new THREE.MeshPhysicalMaterial({ color: 0x3d3f41, roughness: 0.8 }),
        soil: new THREE.MeshStandardMaterial({ color: 0x3b2f25, roughness: 1 }),
        bark: new THREE.MeshStandardMaterial({ color: 0x6b5a48, roughness: 1, flatShading: true }),
        olive: new THREE.MeshStandardMaterial({ color: 0x7d8c63, roughness: 0.9, flatShading: true }),
        corten: new THREE.MeshPhysicalMaterial({ color: 0x6b3620, roughness: 0.9, metalness: 0.3, side: THREE.DoubleSide }),
        gravel: new THREE.MeshStandardMaterial({ color: 0xa39d92, roughness: 1, ...gravelSurface() }),
        stone: new THREE.MeshStandardMaterial({ color: 0x8c877d, roughness: 0.95, flatShading: true }),
        log: new THREE.MeshStandardMaterial({ color: 0x5a4330, roughness: 1 }),
        // charred wood and ash by day; glowing embers once the fire burns (renderer: userData.glow)
        embers: new THREE.MeshStandardMaterial({
            color: 0x2b2420,
            roughness: 1,
            userData: { glow: { dayColour: '#000000', dayIntensity: 0, nightColour: '#ff5a14', nightIntensity: 3.5 } },
        }),
        flame: new THREE.MeshBasicMaterial({
            map: flameTexture(),
            alphaMap: flameNoise(),
            transparent: true,
            blending: THREE.AdditiveBlending,
            depthWrite: false,
            side: THREE.DoubleSide,
            toneMapped: false,
        }),
        fireGlow: new THREE.SpriteMaterial({
            map: glowTexture(),
            color: 0xff8a3a,
            transparent: true,
            opacity: 0.22,
            blending: THREE.AdditiveBlending,
            depthWrite: false,
            toneMapped: false,
        }),
        sparks: new THREE.PointsMaterial({
            color: 0xffb35c,
            size: 0.035,
            transparent: true,
            opacity: 0.9,
            blending: THREE.AdditiveBlending,
            depthWrite: false,
            toneMapped: false,
        }),
    };
}

/** A lounge seat of `width` metres: slatted teak base on an aluminium frame, seat and back cushions, two throw pillows. */
function loungeSofa(m, width) {
    const group = new THREE.Group();
    const depth = 0.85;
    const seatHeight = 0.38;
    const armWidth = 0.1;

    // frame: two side panels and a base
    for (const side of [-1, 1]) {
        group.add(mesh(rounded(armWidth, 0.62, depth, 0.02), m.frame, side * (width / 2 - armWidth / 2), 0.31, 0));
    }
    group.add(mesh(rounded(width - 2 * armWidth, 0.06, depth - 0.04, 0.01), m.frame, 0, 0.12, 0));
    group.add(mesh(woodBox(width - 2 * armWidth, 0.03, depth - 0.08), m.teak, 0, 0.165, 0));
    group.add(mesh(rounded(width - 2 * armWidth - 0.02, 0.55, 0.08, 0.02), m.frame, 0, 0.42, -depth / 2 + 0.04));

    // cushions: one seat per ~0.7 m, back cushions leaning back a little
    const seats = Math.max(1, Math.round((width - 2 * armWidth) / 0.68));
    const seatWidth = (width - 2 * armWidth) / seats;
    for (let i = 0; i < seats; i++) {
        const x = -width / 2 + armWidth + seatWidth * (i + 0.5);
        group.add(mesh(rounded(seatWidth - 0.015, 0.16, depth - 0.12, 0.05), m.cushion, x, seatHeight - 0.08 + 0.08, 0.03));
        const back = mesh(rounded(seatWidth - 0.03, 0.42, 0.14, 0.06), m.cushion, x, seatHeight + 0.27, -depth / 2 + 0.15);
        back.rotation.x = -0.18;
        group.add(back);
    }
    // throw pillows in the corners
    if (width > 1.2) {
        for (const [side, material] of [[-1, m.accent], [1, m.accentWarm]]) {
            const pillow = mesh(rounded(0.42, 0.42, 0.13, 0.06), material, side * (width / 2 - armWidth - 0.28), seatHeight + 0.24, -depth / 2 + 0.28);
            pillow.rotation.set(-0.25, side * -0.35, side * 0.12);
            group.add(pillow);
        }
    } else {
        const pillow = mesh(rounded(0.4, 0.38, 0.12, 0.05), m.accent, 0, seatHeight + 0.22, -depth / 2 + 0.27);
        pillow.rotation.set(-0.2, 0.15, 0.05);
        group.add(pillow);
    }

    return group;
}

function coffeeTable(m) {
    const group = new THREE.Group();
    const [width, depth, height] = [1.1, 0.6, 0.4];
    group.add(mesh(woodBox(width, 0.035, depth), m.teak, 0, height - 0.0175, 0));
    for (const x of [-1, 1]) {
        for (const z of [-1, 1]) {
            group.add(mesh(rounded(0.04, height - 0.035, 0.04, 0.008), m.frame, x * (width / 2 - 0.05), (height - 0.035) / 2, z * (depth / 2 - 0.05)));
        }
    }
    group.add(mesh(woodBox(width - 0.14, 0.02, depth - 0.14), m.teak, 0, 0.12, 0));
    // a bowl and a book for scale
    group.add(mesh(new THREE.SphereGeometry(0.11, 20, 10, 0, Math.PI * 2, Math.PI / 2, Math.PI / 2), m.planter, 0.22, height + 0.11, 0.05));
    group.add(mesh(rounded(0.24, 0.03, 0.17, 0.004), m.accentWarm, -0.25, height + 0.015, -0.05));

    return group;
}

function sideTable(m) {
    const group = new THREE.Group();
    group.add(mesh(new THREE.CylinderGeometry(0.24, 0.24, 0.03, 32), m.teak, 0, 0.5, 0));
    group.add(mesh(new THREE.CylinderGeometry(0.025, 0.035, 0.49, 12), m.frame, 0, 0.245, 0));
    group.add(mesh(new THREE.CylinderGeometry(0.18, 0.2, 0.02, 24), m.frame, 0, 0.01, 0));

    return group;
}

/** Woven outdoor rug with a striped border, drawn on a canvas. */
function rug() {
    const canvas = document.createElement('canvas');
    canvas.width = 512;
    canvas.height = 384;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#d8d0c0';
    ctx.fillRect(0, 0, 512, 384);
    ctx.strokeStyle = '#8f9a7f';
    ctx.lineWidth = 14;
    ctx.strokeRect(26, 26, 460, 332);
    ctx.lineWidth = 4;
    ctx.strokeRect(46, 46, 420, 292);
    for (let y = 0; y < 384; y += 3) {
        ctx.fillStyle = `rgba(0,0,0,${0.03 + (y % 6 === 0 ? 0.03 : 0)})`;
        ctx.fillRect(0, y, 512, 1);
    }
    const texture = new THREE.CanvasTexture(canvas);
    texture.colorSpace = THREE.SRGBColorSpace;
    texture.anisotropy = 8;
    const material = new THREE.MeshStandardMaterial({ map: texture, roughness: 1 });
    const plane = new THREE.Mesh(new THREE.BoxGeometry(2.4, 0.008, 1.7), material);
    plane.position.y = 0.004;
    plane.castShadow = false;

    return plane;
}

/** Square planter with a small olive tree: gnarled trunk, clustered silvery crown. */
function olivePlanter(m) {
    const group = new THREE.Group();
    group.add(mesh(rounded(0.55, 0.6, 0.55, 0.02), m.planter, 0, 0.3, 0));
    group.add(mesh(new THREE.BoxGeometry(0.5, 0.02, 0.5), m.soil, 0, 0.59, 0));

    const random = seeded(11);
    const trunkPath = new THREE.CatmullRomCurve3([
        new THREE.Vector3(0, 0.58, 0),
        new THREE.Vector3(0.05, 0.9, 0.02),
        new THREE.Vector3(-0.04, 1.25, -0.03),
        new THREE.Vector3(0.02, 1.55, 0.01),
    ]);
    group.add(mesh(new THREE.TubeGeometry(trunkPath, 12, 0.045, 6, false), m.bark, 0, 0, 0));
    for (let i = 0; i < 9; i++) {
        const blob = new THREE.IcosahedronGeometry(0.22 + random() * 0.12, 1);
        const crown = mesh(blob, m.olive, (random() - 0.5) * 0.6, 1.5 + random() * 0.45, (random() - 0.5) * 0.6);
        crown.scale.y = 0.75;
        group.add(crown);
    }

    return group;
}

/**
 * A corten fire bowl on a short foot, in a ring of gravel edged with stones
 * (2 m across: the ring defines the model's width). Logs lie crossed in the
 * bowl. Flames and the fire's light are marked userData.litOnly: the renderer
 * shows them only when the mood's light level is above zero (sunset, evening).
 */
function firePit(m) {
    const group = new THREE.Group();
    const random = seeded(23);

    const gravel = mesh(new THREE.CircleGeometry(1, 48).rotateX(-Math.PI / 2), m.gravel, 0, 0.008, 0);
    gravel.userData.noShadow = true;
    group.add(gravel);
    const edge = [];
    for (let i = 0; i < 26; i++) {
        const angle = (i / 26) * Math.PI * 2;
        edge.push(new THREE.DodecahedronGeometry(0.07 + random() * 0.03, 0)
            .scale(1.3, 0.55, 1)
            .rotateY(angle + random())
            .translate(Math.cos(angle) * 0.97, 0.02, Math.sin(angle) * 0.97));
    }
    group.add(new THREE.Mesh(mergeGeometries(edge, false), m.stone));

    // bowl: foot, then a shallow dish with a rolled rim (lathe profile, radius → height)
    const profile = [[0.16, 0], [0.18, 0.02], [0.14, 0.2], [0.12, 0.22], [0.2, 0.24], [0.42, 0.36], [0.47, 0.42], [0.49, 0.44], [0.47, 0.45], [0.44, 0.41], [0.38, 0.36], [0.15, 0.28], [0, 0.27]];
    group.add(mesh(new THREE.LatheGeometry(profile.map(([x, y]) => new THREE.Vector2(x, y)), 40), m.corten, 0, 0, 0));

    const logs = [];
    for (let i = 0; i < 5; i++) {
        const angle = (i / 5) * Math.PI + random() * 0.3;
        logs.push(new THREE.CylinderGeometry(0.04, 0.045, 0.5, 7)
            .rotateZ(Math.PI / 2 - 0.12)
            .rotateY(angle)
            .translate(0, 0.33 + i * 0.03, 0));
    }
    group.add(new THREE.Mesh(mergeGeometries(logs, false), m.log));
    const embers = mesh(new THREE.CylinderGeometry(0.3, 0.3, 0.03, 24), m.embers, 0, 0.31, 0);
    embers.userData.noShadow = true;
    embers.userData.flicker = 'embers';
    group.add(embers);

    // flames: crossed soft cards (outer ring and a brighter, narrower core); their colour runs
    // white-yellow → orange → red upwards, a scrolling noise mask makes them lick (flicker step)
    const cards = [];
    const card = (width, height, turn, x, z) => new THREE.PlaneGeometry(width, height)
        .translate(0, height / 2, 0)
        .rotateY(turn)
        .translate(x, 0, z);
    for (let i = 0; i < 3; i++) {
        cards.push(card(0.62, 0.95, (i * Math.PI) / 3, 0, 0));
        cards.push(card(0.34, 0.6, (i * Math.PI) / 3 + Math.PI / 6, 0, 0));
    }
    for (let i = 0; i < 4; i++) {
        const angle = (i / 4) * Math.PI * 2 + 0.4;
        cards.push(card(0.3, 0.45 + random() * 0.2, angle, Math.cos(angle) * 0.14, Math.sin(angle) * 0.14));
    }
    // origin at the base of the flames, so the flicker stretches them upwards
    const fire = mesh(mergeGeometries(cards, false), m.flame, 0, 0.3, 0);
    fire.userData.litOnly = true;
    fire.userData.flicker = 'flame';
    fire.userData.noShadow = true;
    fire.renderOrder = 2;
    group.add(fire);

    // warm haze around the fire (always faces the camera; not in photos, which have real light)
    const glow = new THREE.Sprite(m.fireGlow);
    glow.position.set(0, 0.5, 0);
    glow.scale.setScalar(FIRE_GLOW_SIZE);
    glow.userData.baseScale = FIRE_GLOW_SIZE;
    glow.userData.litOnly = true;
    glow.userData.flicker = 'glow';
    glow.renderOrder = 1;
    group.add(glow);

    // a few sparks rising and drifting out of the fire
    const sparks = new THREE.Points(new THREE.BufferGeometry().setAttribute('position', new THREE.BufferAttribute(new Float32Array(SPARKS * 3), 3)), m.sparks);
    sparks.position.set(0, 0.45, 0);
    sparks.userData.litOnly = true;
    sparks.userData.flicker = 'sparks';
    sparks.userData.seeds = Array.from({ length: SPARKS }, () => [random(), random(), random()]);
    sparks.frustumCulled = false;
    group.add(sparks);

    const light = new THREE.PointLight(0xff8a3a, 0, 7, 2);
    light.position.set(0, 0.7, 0);
    light.userData.litOnly = true;
    light.userData.baseIntensity = FIRE_LIGHT_INTENSITY;
    light.userData.flicker = 'light';
    group.add(light);
    group.userData.groundCover = true;

    return group;
}

/**
 * One flame card: colour from a white-yellow base through orange to dark red tips,
 * alpha a soft teardrop (wide, bright base; narrow, faint tip).
 */
function flameTexture() {
    const width = 64;
    const height = 128;
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d');
    const image = ctx.createImageData(width, height);
    const stops = [[0, [255, 246, 214]], [0.25, [255, 205, 96]], [0.55, [255, 128, 34]], [0.8, [214, 58, 14]], [1, [120, 20, 6]]];
    const colourAt = (t) => {
        for (let i = 1; i < stops.length; i++) {
            if (t <= stops[i][0]) {
                const [t0, c0] = stops[i - 1];
                const [t1, c1] = stops[i];
                const f = (t - t0) / (t1 - t0);

                return c0.map((c, k) => c + (c1[k] - c) * f);
            }
        }

        return stops[stops.length - 1][1];
    };
    for (let y = 0; y < height; y++) {
        const t = 1 - y / (height - 1); // 0 at the base (bottom row), 1 at the tip
        const halfWidth = 0.42 * Math.sin(Math.PI * Math.min(1, (t + 0.08) / 1.08)) ** 0.8 * (1 - t * 0.55);
        const colour = colourAt(t);
        for (let x = 0; x < width; x++) {
            const u = Math.abs(x / (width - 1) - 0.5);
            const edge = halfWidth > 0 ? Math.max(0, 1 - (u / halfWidth) ** 2) : 0;
            const fade = Math.min(1, t * 8) * (1 - t) ** 0.7;
            const i = (y * width + x) * 4;
            image.data[i] = colour[0];
            image.data[i + 1] = colour[1];
            image.data[i + 2] = colour[2];
            image.data[i + 3] = 255 * edge * fade;
        }
    }
    ctx.putImageData(image, 0, 0);
    const texture = new THREE.CanvasTexture(canvas);
    texture.colorSpace = THREE.SRGBColorSpace;

    return texture;
}

/** Vertical streaky noise, tileable upwards: scrolled by the flicker step, it makes the flames lick. */
function flameNoise() {
    const size = 64;
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = size;
    const ctx = canvas.getContext('2d');
    const image = ctx.createImageData(size, size);
    const random = seeded(31);
    const waves = Array.from({ length: 6 }, () => [1 + Math.floor(random() * 3), random() * Math.PI * 2, 1 + Math.floor(random() * 4), random() * Math.PI * 2]);
    for (let y = 0; y < size; y++) {
        for (let x = 0; x < size; x++) {
            let value = 0;
            for (const [fx, px, fy, py] of waves) {
                value += Math.sin((x / size) * Math.PI * 2 * fx + px + Math.sin((y / size) * Math.PI * 2 * fy + py) * 1.5);
            }
            const v = Math.min(255, Math.max(0, 150 + value * 32));
            const i = (y * size + x) * 4;
            image.data[i] = image.data[i + 1] = image.data[i + 2] = v;
            image.data[i + 3] = 255;
        }
    }
    ctx.putImageData(image, 0, 0);
    const texture = new THREE.CanvasTexture(canvas);
    texture.wrapS = texture.wrapT = THREE.RepeatWrapping;

    return texture;
}

/** Soft radial falloff for the fire's halo. */
function glowTexture() {
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = 64;
    const ctx = canvas.getContext('2d');
    const gradient = ctx.createRadialGradient(32, 32, 0, 32, 32, 32);
    gradient.addColorStop(0, 'rgba(255,255,255,0.8)');
    gradient.addColorStop(0.2, 'rgba(255,255,255,0.25)');
    gradient.addColorStop(0.55, 'rgba(255,255,255,0.05)');
    gradient.addColorStop(1, 'rgba(255,255,255,0)');
    ctx.fillStyle = gradient;
    ctx.fillRect(0, 0, 64, 64);
    const texture = new THREE.CanvasTexture(canvas);
    texture.colorSpace = THREE.SRGBColorSpace;

    return texture;
}

/** Fine speckle for the gravel ring. */
function gravelSurface() {
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = 128;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#9d978c';
    ctx.fillRect(0, 0, 128, 128);
    const random = seeded(5);
    for (let i = 0; i < 1400; i++) {
        const shade = 120 + Math.floor(random() * 90);
        ctx.fillStyle = `rgb(${shade},${shade - 4},${shade - 10})`;
        ctx.fillRect(random() * 128, random() * 128, 1 + random() * 2, 1 + random() * 2);
    }
    const map = new THREE.CanvasTexture(canvas);
    map.colorSpace = THREE.SRGBColorSpace;
    map.wrapS = map.wrapT = THREE.RepeatWrapping;
    map.repeat.set(4, 4);

    return { map };
}

/**
 * One mesh per material instead of one per cushion and leg: a sofa goes from
 * ~30 draw calls to 4, in every render pass.
 */
function mergeByMaterial(group) {
    group.updateMatrixWorld(true);
    const byMaterial = new Map();
    group.traverse((object) => {
        if (!object.isMesh) {
            return;
        }
        const geometry = object.geometry.index ? object.geometry.toNonIndexed() : object.geometry.clone();
        for (const name of Object.keys(geometry.attributes)) {
            if (!['position', 'normal', 'uv'].includes(name)) {
                geometry.deleteAttribute(name);
            }
        }
        geometry.applyMatrix4(object.matrixWorld);
        if (!byMaterial.has(object.material)) {
            byMaterial.set(object.material, []);
        }
        byMaterial.get(object.material).push(geometry);
    });
    const merged = new THREE.Group();
    for (const [material, geometries] of byMaterial) {
        merged.add(new THREE.Mesh(mergeGeometries(geometries, false), material));
        geometries.forEach((geometry) => geometry.dispose());
    }

    return merged;
}

function named(name, object) {
    object.name = name;

    return object;
}

function mesh(geometry, material, x, y, z) {
    const result = new THREE.Mesh(geometry, material);
    result.position.set(x, y, z);

    return result;
}

function rounded(width, height, depth, radius) {
    return new RoundedBoxGeometry(width, height, depth, 3, Math.min(radius, Math.min(width, height, depth) / 2 - 0.001));
}

/** A box with plank texture at real-world scale. */
function woodBox(width, height, depth) {
    return applyMetricUVs(new THREE.BoxGeometry(width, height, depth), 0.6);
}

function seeded(seed) {
    return () => {
        seed = (seed * 16807) % 2147483647;

        return (seed - 1) / 2147483646;
    };
}
