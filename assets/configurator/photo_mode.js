import * as THREE from 'three';
import { mergeGeometries } from 'three/examples/jsm/utils/BufferGeometryUtils.js';
import { RGBELoader } from 'three/examples/jsm/loaders/RGBELoader.js';
import { PhysicalCamera, WebGLPathTracer } from 'three-gpu-pathtracer';
import { PhotoFinish } from './photo_finish.js';
import { PhotoStones } from './photo_stones.js';

/**
 * Photo mode: renders the current view with a progressive path tracer
 * (three-gpu-pathtracer) for a near-photographic still, light bounces, soft
 * shadows, real glass, reflections, while the live view is paused.
 *
 * The path tracer only understands standard materials, plain meshes and an
 * equirectangular sky. So for the photo the live scene is prepared in place
 * and restored afterwards:
 * - the procedural sky dome and the cloud layer (shaders) are hidden; a
 *   photographed sky (HDRI, CC0 from Poly Haven) lights the scene instead and
 *   is turned so its sun stands where the live view's sun does;
 * - boxes drawn with the stone texture (gabion fill) are filled with real
 *   stones (photo_stones.js), the box darkened behind them;
 * - instanced meshes (forest) are merged into one mesh each;
 * - unlit materials (flames, distant mountains) become emissive;
 * - glass gets real transmission;
 * - the live view's helper light pools and sun are switched off: the sky's own
 *   sun and the lamps light the scene.
 */
export class PhotoMode {
    /**
     * Samples when the environment asks for none (the studio). The photo is not blurred
     * (photo_finish.js), noise only goes with samples: outdoors 32 by day, 64 in the evening
     * (start's `samples`), ~25 s and ~55 s on an M-series laptop at 2 MP (about 0.6 s a
     * sample, 5 s to prepare the scene).
     */
    static TARGET_SAMPLES = 32;
    /** the photo's size in pixels at most (#capResolution) */
    static MAX_PIXELS = 2_000_000;
    /** outdoors nearly all light arrives within three bounces; more only cost time */
    static BOUNCES = 3;
    /**
     * three-gpu-pathtracer (0.0.23) sizes its random-number texture by bounces +
     * transmissiveBounces + 5 but reads random dimension 0…19 along that axis: dimensions past
     * the width read 0 in every sample. Among them are 14 (the direction through glass) and 15
     * (diffuse, gloss or glass at a hit), so each pixel made the same choice in every sample and
     * glass and metal showed a fixed salt-and-pepper pattern that no number of samples cleared.
     * Extra transmissive bounces cost nothing where no ray passes through glass.
     */
    static RANDOM_DIMENSIONS = 20;
    static TRANSMISSIVE_BOUNCES = PhotoMode.RANDOM_DIMENSIONS - 5 - PhotoMode.BOUNCES;
    /**
     * Point lights (lamps, fire) next to a wall light it far more in a path tracer than
     * in the live view's approximation; unlit flames need a real glow to read as fire.
     */
    static POINT_LIGHT_SCALE = 0.45;
    static FLAME_GLOW = 9;
    /**
     * Small, very bright emissive surfaces (LED spots) are found by chance by bounce rays and
     * leave white specks; their light comes from the spot lights anyway.
     */
    static MAX_EMISSIVE = 4;
    /**
     * Leaves let about a third of the light through; the path tracer's leaf cards let none,
     * so a bush is a dark ball lit only on its sunny side. Cut-out foliage glows faintly in
     * its own colours instead, in proportion to the sky's light (none at night).
     */
    static FOLIAGE_GLOW = 0.25;
    /**
     * The path tracer has no light sampling for glowing surfaces: their light reaches a wall
     * only when a bounce ray happens to hit them. At dusk an LED spot or a lit window is
     * 20–40 times brighter than everything around it, so each lucky hit is a white speck.
     * Light from a glowing surface met by a bounce ray is limited to this (a firefly clamp);
     * the camera still sees the surface at full strength, and the lamps' real lights stay.
     */
    static INDIRECT_GLOW_LIMIT = 0.3;
    /** procedural texture of a stone fill (Core\Configurator\Scene\ProceduralTexture::Stones) */
    static STONE_TEXTURE = 'stones';
    /** the fill box between the real stones: the shadowed gaps */
    static STONE_GAPS = 0.5;
    /**
     * aperture of the photo (the path tracer models a real lens, 35 mm film): at f/4 a
     * fence 5 m away stays sharp while the scenery 40 m behind it softens by ~1 % of
     * the image width; wider apertures start to look like a miniature
     */
    static F_STOP = 4;
    static APERTURE_BLADES = 7;

    #renderer;
    #scene;
    #camera;
    #tracer = null;
    #undo = [];
    #frame = null;
    #session = 0;
    #finish = null;
    /** samples of the image last developed */
    #developed = -1;
    #done = false;

    constructor(renderer, scene, camera) {
        this.#renderer = renderer;
        this.#scene = scene;
        this.#camera = camera;
    }

    get active() {
        return this.#session > 0 && this.#undo.length > 0;
    }

    get done() {
        return this.#done;
    }

    /**
     * @param {{ skyUrl: string, sunDirection: THREE.Vector3, skyIntensity?: number, backgroundIntensity?: number, onProgress?: (fraction: number) => void, onDone?: () => void }} options
     *        skyIntensity: the sky's light on the scene; backgroundIntensity: how bright the sky looks;
     *        stoneFills: scene parts (boxes) to fill with stones;
     *        focusDistance: metres from the camera to what should be sharp (none: all sharp);
     *        samples: how many to accumulate (none: TARGET_SAMPLES)
     */
    async start({ skyUrl, sunDirection, skyIntensity = 1, backgroundIntensity = skyIntensity, samples = null, stoneFills = [], focusDistance = null, onProgress = () => {}, onDone = () => {} }) {
        this.stop();
        const session = ++this.#session;
        this.#done = false;
        this.#developed = -1;
        const sky = await loadSky(skyUrl);
        if (session !== this.#session) {
            return; // cancelled while loading
        }
        this.#fillWithStones(stoneFills);
        this.#prepare(sky, sunDirection, skyIntensity, backgroundIntensity);
        this.#capResolution();

        this.#tracer ??= this.#createTracer();
        // let the panel paint before the (synchronous) BVH build blocks the main thread;
        // the async variant needs a BVH web worker, which importmap modules cannot provide
        await new Promise((resolve) => requestAnimationFrame(() => setTimeout(resolve)));
        if (session !== this.#session) {
            return;
        }
        const target = samples ?? PhotoMode.TARGET_SAMPLES;
        const started = performance.now();
        this.#tracer.setScene(this.#scene, this.#lens(focusDistance));
        this.buildMs = Math.round(performance.now() - started);
        const step = () => {
            if (session !== this.#session) {
                return;
            }
            this.#tracer.renderSample();
            const done = Math.floor(this.#tracer.samples);
            onProgress(Math.min(1, done / target));
            if (done >= target) {
                this.#done = true;
                this.#frame = null;
                onDone();

                return;
            }
            this.#frame = requestAnimationFrame(step);
        };
        this.#frame = requestAnimationFrame(step);
    }

    /** Ends the photo and gives the scene back to the live view. */
    stop() {
        if (this.#frame !== null) {
            cancelAnimationFrame(this.#frame);
            this.#frame = null;
        }
        this.#session++;
        this.#done = false;
        while (this.#undo.length > 0) {
            this.#undo.pop()();
        }
    }

    /**
     * The finished image as a PNG blob. The canvas keeps no drawing buffer,
     * so the photo is developed again and read in the same task.
     */
    toBlob() {
        return new Promise((resolve) => {
            this.#finish.render(this.#tracer.target);
            this.#renderer.domElement.toBlob(resolve, 'image/png');
        });
    }

    dispose() {
        this.stop();
        this.#tracer?.dispose();
        this.#tracer = null;
        this.#finish?.dispose();
    }

    #createTracer() {
        const tracer = new WebGLPathTracer(this.#renderer);
        tracer.bounces = PhotoMode.BOUNCES;
        tracer.transmissiveBounces = PhotoMode.TRANSMISSIVE_BOUNCES;
        tracer.filterGlossyFactor = 0.5;
        tracer.minSamples = 1;
        tracer.fadeDuration = 0;
        tracer.renderDelay = 0;
        tracer.dynamicLowRes = false;
        tracer.tiles.set(2, 2);
        limitIndirectGlow(tracer, PhotoMode.INDIRECT_GLOW_LIMIT);
        // the accumulated image is developed into the photo (photo_finish.js)
        // each call renders one tile (a quarter sample): develop only whole samples
        tracer.renderToCanvasCallback = (target) => {
            if (!Number.isInteger(tracer.samples) || tracer.samples === this.#developed) {
                return;
            }
            this.#developed = tracer.samples;
            this.#finish ??= new PhotoFinish(this.#renderer);
            this.#finish.render(target);
        };

        return tracer;
    }

    #prepare(sky, sunDirection, skyIntensity, backgroundIntensity) {
        const scene = this.#scene;
        this.#keep(scene, ['background', 'environment', 'environmentIntensity', 'backgroundIntensity', 'fog']);
        const environmentRotation = scene.environmentRotation.clone();
        const backgroundRotation = scene.backgroundRotation.clone();
        this.#undo.push(() => {
            scene.environmentRotation.copy(environmentRotation);
            scene.backgroundRotation.copy(backgroundRotation);
        });

        scene.background = sky;
        scene.environment = sky;
        scene.environmentIntensity = skyIntensity;
        scene.backgroundIntensity = backgroundIntensity;
        scene.fog = null;
        // turn the sky about the vertical so its sun stands where the live sun does
        const turn = Math.atan2(sunDirection.z, sunDirection.x) - sky.userData.sunAzimuth;
        scene.environmentRotation.set(0, -turn, 0);
        scene.backgroundRotation.set(0, -turn, 0);

        const merged = [];
        const foliage = new Set();
        scene.traverse((object) => {
            if (object.isLight) {
                // the live sun stays (sampled directly: crisp, noise-free shadows); fill light comes from the sky
                // dark lights (lamps by day) would still be picked for light samples: wasted rays, more noise
                if (object.isHemisphereLight || object.isAmbientLight || object.intensity === 0) {
                    this.#keep(object, ['visible']);
                    object.visible = false;
                } else if (object.isPointLight) {
                    this.#keep(object, ['intensity']);
                    object.intensity *= PhotoMode.POINT_LIGHT_SCALE;
                }

                return;
            }
            if (!object.isMesh || !object.visible) {
                return;
            }
            const material = object.material;
            if (material.isShaderMaterial || material.isRawShaderMaterial || material.blending === THREE.AdditiveBlending && !material.userData.photoEmissive && !object.userData.flicker) {
                this.#keep(object, ['visible']);
                object.visible = false;

                return;
            }
            if (material.isMeshBasicMaterial) {
                this.#keep(object, ['material']);
                object.material = emissiveCopy(material, object.userData.flicker === 'flame' ? PhotoMode.FLAME_GLOW : 1);
            }
            if (material.emissiveIntensity > PhotoMode.MAX_EMISSIVE && object.userData.flicker !== 'flame') {
                this.#keep(material, ['emissiveIntensity']);
                material.emissiveIntensity = PhotoMode.MAX_EMISSIVE;
            }
            if (material.alphaTest > 0 && material.map && !foliage.has(material)) {
                foliage.add(material);
                this.#keep(material, ['emissive', 'emissiveMap', 'emissiveIntensity']);
                this.#undo.push(() => { material.needsUpdate = true; });
                material.emissive = new THREE.Color(0xffffff);
                material.emissiveMap = material.map;
                material.emissiveIntensity = PhotoMode.FOLIAGE_GLOW * skyIntensity;
            }
            if (material.userData?.isGlass) {
                this.#keep(material, ['transmission', 'opacity', 'transparent', 'thickness', 'ior']);
                material.transmission = 1;
                material.opacity = 1;
                material.transparent = false;
                material.thickness = 0.01;
            }
            if (object.isInstancedMesh) {
                merged.push(object);
            }
        });
        for (const instanced of merged) {
            const mesh = mergeInstances(instanced);
            instanced.parent.add(mesh);
            this.#keep(instanced, ['visible']);
            instanced.visible = false;
            this.#undo.push(() => {
                mesh.removeFromParent();
                mesh.geometry.dispose();
            });
        }

        // Shadows as in the live view: scenery that casts none there (hedge, trees, hills,
        // neighbouring houses) does not block the sun here either, or a low sunset sun would
        // never reach the house. The path tracer reads castShadow from the material.
        const casting = new Set();
        const silent = new Set();
        scene.traverseVisible((object) => {
            if (object.isMesh) {
                [object.material].flat().forEach((material) => (object.castShadow ? casting : silent).add(material));
            }
        });
        for (const material of silent) {
            if (!casting.has(material)) {
                this.#keep(material, ['castShadow']);
                material.castShadow = false;
            }
        }

        // The path tracer (0.0.23) gives each mesh's triangles the material at the MESH's
        // index in the flat material list, so a mesh with several materials shifts every mesh
        // after it: split those into one mesh per material.
        const multiMaterial = [];
        scene.traverseVisible((object) => {
            if (object.isMesh && Array.isArray(object.material)) {
                multiMaterial.push(object);
            }
        });
        for (const mesh of multiMaterial) {
            const parts = splitByMaterial(mesh);
            parts.forEach((part) => mesh.parent.add(part));
            this.#keep(mesh, ['visible']);
            mesh.visible = false;
            this.#undo.push(() => parts.forEach((part) => {
                part.removeFromParent();
                part.geometry.dispose();
            }));
        }

        // The path tracer merges all geometries into one, with the attribute types of the
        // FIRST geometry: quantised GLB attributes (normalised Int16 positions of the bushes)
        // would squeeze the whole scene into ±1 m, RGB colours next to the RGBA colours it
        // adds to meshes without vertex colours would be copied with the wrong stride.
        // So every attribute becomes plain Float32, colours RGBA.
        const converted = new Set();
        scene.traverseVisible((object) => {
            if (!object.isMesh || converted.has(object.geometry)) {
                return;
            }
            converted.add(object.geometry);
            const geometry = object.geometry;
            for (const name of PATH_TRACED_ATTRIBUTES) {
                const attribute = geometry.getAttribute(name);
                if (!attribute) {
                    continue;
                }
                const itemSize = name === 'color' ? 4 : attribute.itemSize;
                if (attribute.isBufferAttribute && attribute.array instanceof Float32Array && !attribute.normalized && itemSize === attribute.itemSize) {
                    continue;
                }
                geometry.setAttribute(name, toFloat32(attribute, itemSize));
                this.#undo.push(() => geometry.setAttribute(name, attribute));
            }
        });
    }

    /**
     * Renders the photo at no more than MAX_PIXELS: the cost of a sample grows
     * with the pixel count, and 2 MP (1900 × 1050 in a wide view) is plenty for
     * a web page or an e-mail. The live view's pixel ratio comes back afterwards.
     */
    #capResolution() {
        const size = this.#renderer.getSize(new THREE.Vector2());
        const ratio = this.#renderer.getPixelRatio();
        const capped = Math.min(ratio, Math.sqrt(PhotoMode.MAX_PIXELS / (size.x * size.y)));
        if (capped >= ratio) {
            return;
        }
        this.#renderer.setPixelRatio(capped);
        this.#undo.push(() => this.#renderer.setPixelRatio(ratio));
    }

    /**
     * The live camera as a photographic lens: focused on the product at
     * F_STOP, so the background (forest, neighbouring houses) softens a
     * little, as in a real photo.
     */
    #lens(focusDistance) {
        if (!focusDistance) {
            return this.#camera;
        }
        const lens = new PhysicalCamera();
        lens.copy(this.#camera);
        lens.fStop = PhotoMode.F_STOP;
        lens.apertureBlades = PhotoMode.APERTURE_BLADES;
        lens.focusDistance = focusDistance;
        lens.updateMatrixWorld();

        return lens;
    }

    #fillWithStones(fills) {
        const stones = PhotoStones.create(fills);
        if (!stones) {
            return;
        }
        this.#scene.traverse((object) => {
            const material = object.material;
            if (object.isMesh && material?.userData?.textureName === PhotoMode.STONE_TEXTURE) {
                this.#keep(material, ['color']);
                material.color = material.color.clone().multiplyScalar(PhotoMode.STONE_GAPS);
            }
        });
        this.#scene.add(stones);
        this.#undo.push(() => {
            stones.removeFromParent();
            stones.geometry.dispose();
            stones.material.dispose();
        });
    }

    /** Remembers properties to restore when the photo ends. */
    #keep(target, keys) {
        const saved = Object.fromEntries(keys.map((key) => [key, target[key]]));
        this.#undo.push(() => Object.assign(target, saved));
    }
}

const PATH_TRACED_ATTRIBUTES = ['position', 'normal', 'uv', 'color', 'tangent'];

/** lines of three-gpu-pathtracer's (0.0.23) shader the glow limit hooks into */
const SHADER_MAIN = 'void main() {';
/** start of each bounce; transmissiveRay is still "camera ray, maybe through glass" here */
const BOUNCE_START = 'state.firstRay = i == 0 && state.transmissiveTraversals == transmissiveBounces;';
/** where a surface's glow is added to the path */
const EMISSION_LINE = 'gl_FragColor.rgb += ( surf.emission * state.throughputColor );';

/**
 * Patches the path tracing shader: glow met by a bounce ray (not seen by the camera,
 * directly or through glass) adds at most `limit` to the pixel, scaled down evenly so it
 * keeps its colour (see INDIRECT_GLOW_LIMIT). Relies on the tracer's internals; if they
 * change, the photo renders unclamped.
 */
function limitIndirectGlow(tracer, limit) {
    const material = tracer._pathTracer?.material;
    const shader = material?.fragmentShader ?? '';
    if (![SHADER_MAIN, BOUNCE_START, EMISSION_LINE].every((line) => shader.includes(line))) {
        console.warn('PhotoMode: path tracer shader changed, glow is not clamped');

        return;
    }
    material.uniforms.indirectGlowLimit = { value: limit };
    material.fragmentShader = shader
        .replace(SHADER_MAIN, `uniform float indirectGlowLimit;\n${SHADER_MAIN}`)
        .replace(BOUNCE_START, `${BOUNCE_START}\nbool seenByCamera = state.firstRay || state.transmissiveRay;`)
        .replace(EMISSION_LINE, /* glsl */ `{
            vec3 glow = surf.emission * state.throughputColor;
            float brightest = max( glow.r, max( glow.g, glow.b ) );
            if ( ! seenByCamera && brightest > indirectGlowLimit ) {
                glow *= indirectGlowLimit / brightest;
            }
            gl_FragColor.rgb += glow;
        }`);
    material.needsUpdate = true;
}

/** A plain Float32 copy of any attribute (interleaved, normalised, quantised); extra components are filled with 1. */
function toFloat32(attribute, itemSize) {
    const array = new Float32Array(attribute.count * itemSize).fill(1);
    const getters = ['getX', 'getY', 'getZ', 'getW'];
    for (let i = 0; i < attribute.count; i++) {
        for (let c = 0; c < Math.min(itemSize, attribute.itemSize); c++) {
            array[i * itemSize + c] = attribute[getters[c]](i);
        }
    }

    return new THREE.BufferAttribute(array, itemSize);
}

const skies = new Map();

/**
 * Loads an HDRI once and prepares it as sky light: userData.sunAzimuth is the
 * direction of its sun (atan2(z, x)); the sun disc itself is clamped to the
 * sky's brightness, because the scene's own directional light is the sun
 * (two suns would double the light, and a tiny bright disc only adds noise).
 * The sky is scaled to an average brightness of 1, so moods can set its
 * strength with environmentIntensity alone.
 */
function loadSky(url) {
    if (!skies.has(url)) {
        skies.set(url, new RGBELoader().setDataType(THREE.FloatType).loadAsync(url).then((texture) => {
            texture.mapping = THREE.EquirectangularReflectionMapping;
            texture.userData.sunAzimuth = brightestAzimuth(texture);
            normaliseSky(texture);
            texture.needsUpdate = true;

            return texture;
        }));
    }

    return skies.get(url);
}

/** Clamps the sun to the 99.5th percentile of the sky and scales the sky to a mean of 1. */
function normaliseSky(texture) {
    const { data, width, height } = texture.image;
    const luminances = [];
    for (let y = 0; y < height / 2; y += 4) {
        for (let x = 0; x < width; x += 4) {
            const i = (y * width + x) * 4;
            luminances.push(0.2126 * data[i] + 0.7152 * data[i + 1] + 0.0722 * data[i + 2]);
        }
    }
    luminances.sort((a, b) => a - b);
    const ceiling = luminances[Math.floor(luminances.length * 0.995)];
    let sum = 0;
    let count = 0;
    for (let i = 0; i < data.length; i += 4) {
        const luminance = 0.2126 * data[i] + 0.7152 * data[i + 1] + 0.0722 * data[i + 2];
        const scale = luminance > ceiling ? ceiling / luminance : 1;
        data[i] *= scale;
        data[i + 1] *= scale;
        data[i + 2] *= scale;
        if (i < data.length / 2) {
            sum += luminance * scale;
            count++;
        }
    }
    const mean = sum / count;
    for (let i = 0; i < data.length; i += 4) {
        data[i] /= mean;
        data[i + 1] /= mean;
        data[i + 2] /= mean;
    }
}

function brightestAzimuth(texture) {
    const { data, width, height } = texture.image;
    const value = (i) => data[i];
    let best = -1;
    let bestColumn = 0;
    // the sky half only; sample every other pixel, the sun is several pixels wide
    for (let y = 0; y < height / 2; y += 2) {
        for (let x = 0; x < width; x += 2) {
            const i = (y * width + x) * 4;
            const luminance = value(i) + value(i + 1) + value(i + 2);
            if (luminance > best) {
                best = luminance;
                bestColumn = x;
            }
        }
    }

    // three.js equirect lookup: u = atan2(z, x) / 2π + 0.5
    return ((bestColumn + 0.5) / width - 0.5) * Math.PI * 2;
}

/** An unlit material as an emissive one: it looks the same, but the path tracer understands it. */
function emissiveCopy(material, intensity = 1) {
    return new THREE.MeshStandardMaterial({
        color: 0x000000,
        emissive: material.color,
        emissiveMap: material.map,
        // the map's alpha shapes the flame; with a black colour it adds no diffuse light
        map: material.map,
        alphaMap: material.alphaMap,
        emissiveIntensity: intensity,
        vertexColors: material.vertexColors,
        transparent: material.transparent,
        opacity: material.opacity,
        side: material.side,
    });
}

/** One mesh per geometry group of a multi-material mesh, sharing its attributes. */
function splitByMaterial(mesh) {
    const source = mesh.geometry;
    const index = source.index;

    return source.groups.map((group) => {
        const geometry = new THREE.BufferGeometry();
        for (const [name, attribute] of Object.entries(source.attributes)) {
            geometry.setAttribute(name, attribute);
        }
        const indices = [];
        for (let i = group.start; i < group.start + group.count; i++) {
            indices.push(index ? index.getX(i) : i);
        }
        geometry.setIndex(indices);
        const part = new THREE.Mesh(geometry, mesh.material[group.materialIndex]);
        part.position.copy(mesh.position);
        part.quaternion.copy(mesh.quaternion);
        part.scale.copy(mesh.scale);
        part.matrixAutoUpdate = mesh.matrixAutoUpdate;
        part.matrix.copy(mesh.matrix);

        return part;
    });
}

/** One plain mesh from an InstancedMesh; instance colours become vertex colours. */
function mergeInstances(instanced) {
    const matrix = new THREE.Matrix4();
    const colour = new THREE.Color();
    const source = instanced.geometry.index ? instanced.geometry.toNonIndexed() : instanced.geometry;
    const geometries = [];
    for (let i = 0; i < instanced.count; i++) {
        instanced.getMatrixAt(i, matrix);
        const geometry = source.clone().applyMatrix4(matrix);
        if (instanced.instanceColor) {
            instanced.getColorAt(i, colour);
            const count = geometry.attributes.position.count;
            const base = geometry.attributes.color;
            const colours = new Float32Array(count * 3);
            for (let v = 0; v < count; v++) {
                colours[v * 3] = colour.r * (base ? base.getX(v) : 1);
                colours[v * 3 + 1] = colour.g * (base ? base.getY(v) : 1);
                colours[v * 3 + 2] = colour.b * (base ? base.getZ(v) : 1);
            }
            geometry.setAttribute('color', new THREE.BufferAttribute(colours, 3));
        }
        geometries.push(geometry);
    }
    const material = instanced.instanceColor && !instanced.material.vertexColors
        ? Object.assign(instanced.material.clone(), { vertexColors: true })
        : instanced.material;
    const mesh = new THREE.Mesh(mergeGeometries(geometries, false), material);
    mesh.matrix.copy(instanced.matrix);
    mesh.matrixAutoUpdate = instanced.matrixAutoUpdate;
    mesh.position.copy(instanced.position);
    mesh.quaternion.copy(instanced.quaternion);
    mesh.scale.copy(instanced.scale);
    geometries.forEach((geometry) => geometry.dispose());

    return mesh;
}
