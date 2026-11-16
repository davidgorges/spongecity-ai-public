import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { RoundedBoxGeometry } from 'three/examples/jsm/geometries/RoundedBoxGeometry.js';
import { mergeGeometries } from 'three/examples/jsm/utils/BufferGeometryUtils.js';
import { KeyboardNavigation } from './keyboard_navigation.js';
import { ModelLibrary } from './model_library.js';
import { PartPicker } from './part_picker.js';
import { OutdoorEnvironment } from './outdoor_environment.js';
import { StudioEnvironment } from './studio_environment.js';
import { PhotoMode } from './photo_mode.js';
import { applyPhotoTexture, hasPhotoTexture, removePhotoTexture } from './photo_textures.js';
import { GpuTimer, PerformanceGovernor } from './performance_governor.js';
import { createComposer } from './post_processing.js';
import { applyMetricUVs, applyUprightUVs, proceduralTexture } from './procedural_textures.js';
import { TIERS } from './quality_tiers.js';
import { guardNormalMaps } from './shader_guards.js';
import { wireMeshGeometry } from './wire_mesh.js';
import { ProductMask } from './product_mask.js';
import { InfiltrationOverlay } from './site_conditions.js';
import { TerrainField } from './terrain.js';

/** Whether a model (or the node the model library wraps) keeps the grass off the ground under it. */
function coversGround(model) {
    let covers = false;
    model.traverse((object) => {
        covers ||= object.userData.groundCover === true;
    });

    return covers;
}

// before any material compiles: three.js keeps one copy of its shader chunks
guardNormalMaps();

/**
 * Renders the scene JSON of Core\Configurator\Scene\SceneSerializer:
 * { materials: { key: {color, roughness, metalness, opacity, transmission, ior, clearcoat, texture, textureSize, emissive} },
 *   parts: [{ shape: 'box', size: [x,y,z], position: [x,y,z], rotation: [x,y,z], material, sku, label }
 *         | { shape: 'model', url, variant, size: [x,y,z] (0 = free), position: bottom centre, rotation, … }] }
 * in metres, Y up, inside the environment its `setting` names: an
 * OutdoorEnvironment (garden) or a StudioEnvironment (studio).
 *
 * Quality comes in tiers (quality_tiers.js); a PerformanceGovernor steps
 * between them while the camera moves, up to the maximum the user allows.
 * Frames are rendered on demand (after an update or while the camera moves).
 * Besides mouse/touch orbiting, the keyboard walks the camera (KeyboardNavigation).
 */
export class SceneRenderer {
    static SETTING_GARDEN = 'garden';
    static SETTING_STUDIO = 'studio';
    /** share of the view's width or height the product fills after fitView() */
    static FIT_FILL = 0.72;
    static VIEW_DIRECTION = new THREE.Vector3(0.82, 0.27, 1).normalize();
    /** bird's-eye overview of a site seen from above (scene environment hint `aerial`): ~50° elevation, over the buildings around */
    /** the plot is a flat slab: its own fit leaves no room for the buildings around it */
    static MAX_DISTANCE = 45;
    /** a whole yard with its surroundings is much wider than a product */
    static AERIAL_MAX_DISTANCE = 220;
    static AERIAL_MARGIN = 1.8;
    static AERIAL_DIRECTION = new THREE.Vector3(0.55, 1.15, 0.75).normalize();
    /** a product lower than this share of its footprint's diagonal is seen from lower down… */
    static LOW_PRODUCT_RATIO = 0.45;
    /** …down to this share of the default elevation */
    static MIN_ELEVATION_SHARE = 0.45;
    static INTRO_MS = 1100;
    /** named views (showView): where the camera looks from; overview is fitView()'s */
    static VIEW_OVERVIEW = 'overview';
    static VIEW_DIRECTIONS = {
        front: new THREE.Vector3(0, 0.22, 1).normalize(),
        left: new THREE.Vector3(-1, 0.3, 0.25).normalize(),
        right: new THREE.Vector3(1, 0.3, 0.25).normalize(),
        top: new THREE.Vector3(0, 1, 0.02).normalize(),
    };
    /** a focused part is seen from at least this far, metres */
    static MIN_FOCUS_DISTANCE = 3;
    static KEEP_RENDERING_MS = 200;
    /** a frame loop restarting within this of the last frame continues its measurement */
    static RESUME_MS = 50;
    /** after new scenery arrives, frame times are not judged for this long (performance_governor.js) */
    static SCENERY_SETTLE_MS = 1500;
    /**
     * The near plane follows the camera's distance to what it looks at: depth precision falls with
     * distance²/near, and with a fixed 0.1 m the ground layers (a few millimetres apart) z-fight
     * and flicker from an aerial view. Nothing is ever this close to an orbiting camera.
     */
    static NEAR_PER_DISTANCE = 0.04;
    static MIN_NEAR = 0.1;
    static MAX_NEAR = 12;
    /** profiles get rounded edges; panes stay sharp */
    static EDGE_RADIUS = 0.004;
    static MIN_THICKNESS_FOR_ROUNDING = 0.02;
    static FPS_SMOOTHING = 0.1;
    static MAX_AZIMUTH = Math.PI * 0.62;
    /** the run highlight stands this much higher and longer than the fence, metres */
    static HIGHLIGHT_MARGIN = 0.15;
    static HIGHLIGHT_DEPTH = 0.3;
    static FADE_IN_MS = 600;
    static LIGHT_POOL_RADIUS = 1.1;
    static LIGHT_POOL_HEIGHT = 0.016;
    static MAX_LAMP_LIGHTS = 4;
    static LAMP_LIGHT_INTENSITY = 5;
    /** half-angle of the downlights' cone (radians) and their size in metres (soft shadows in photos) */
    static LAMP_CONE = 1.1;
    static LAMP_RADIUS = 0.15;
    static DECOR_FADE_MS = 300;
    /** fire flicker: ~15 frames a second is enough to read as fire, at a quarter of a full animation's cost */
    static FLICKER_INTERVAL_MS = 66;

    #container;
    #renderer;
    #scene = new THREE.Scene();
    #camera = new THREE.PerspectiveCamera(38, 1, 0.1, 5000);
    #controls;
    #keyboard;
    #lastFrame = 0;
    #lastFrameDrawn = -Infinity;
    #environment;
    #composer = null;
    #governor;
    #gpuTimer;
    #fps = 0;
    #frameMs = 0;
    #measuring = false;
    #content = new THREE.Group();
    /** scenery: models and parts without SKU (house, terrace); kept apart from #content so camera fit and shadows frame the product only */
    #scenery = new THREE.Group();
    #backdrop = new THREE.Group();
    /** decoration models (furniture, fire pit: parts with decor = true); the customer can fade them out */
    #decor = new THREE.Group();
    #decorVisible = true;
    #conditions = null;
    #conditionsData = null;
    /** the real ground of an aerial site: draped parts follow it (flat until loaded) */
    #terrain = TerrainField.FLAT;
    #decorAlpha = 1;
    #decorFade = null;
    #partFootprints = [];
    /** flames, embers and fire lights (userData.flicker) and the timer that animates them */
    #flickering = [];
    #flickerTimer = null;
    #onScreen = true;
    #visibilityObserver;
    /** created on first use: the path tracer is only loaded into memory when a photo is taken */
    #photo = null;
    /** whether materials use the photographed textures (ultra tier, photo mode) */
    #photoTextures = false;
    #productCentre = new THREE.Vector2();
    /** evening: pools of light under lamps and a few real lights among them; at sunset only the dimmed lights */
    #lamps = new THREE.Group();
    #lampPositions = [];
    #picker;
    #pointer = new THREE.Vector2();
    #models = new ModelLibrary();
    #generation = 0;
    #materials = new Map();
    #materialDefinitions = {};
    #lastSceneData = null;
    #geometries = new Map();
    #resizeObserver;
    #looping = false;
    #keepRenderingUntil = 0;
    #intro = null;
    #hasFitted = false;
    #aerial = false;
    #highlight = null;
    #reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /**
     * @param {HTMLElement} container
     * @param {{startTier: number, maxTier: number, pinned?: boolean, setting?: string}} options quality
     *        indexes into TIERS; pinned keeps startTier (debugging: compare tiers); setting picks the
     *        environment (the scene's `setting`, fixed for a product)
     */
    constructor(container, { startTier, maxTier, pinned = false, environmentModels = {}, setting = SceneRenderer.SETTING_GARDEN }) {
        this.#container = container;

        this.#renderer = new THREE.WebGLRenderer({ antialias: true, powerPreference: 'high-performance' });
        this.#renderer.shadowMap.enabled = true;
        this.#renderer.info.autoReset = false; // count all passes of a frame
        this.#renderer.shadowMap.type = THREE.PCFSoftShadowMap;
        this.#renderer.toneMapping = THREE.AgXToneMapping;
        this.#renderer.toneMappingExposure = 0.95;
        this.#renderer.outputColorSpace = THREE.SRGBColorSpace;
        // fade in once the first frame is drawn, instead of popping in
        this.#renderer.domElement.style.opacity = '0';
        this.#renderer.domElement.style.transition = `opacity ${SceneRenderer.FADE_IN_MS}ms ease-out`;
        container.appendChild(this.#renderer.domElement);

        this.#environment = setting === SceneRenderer.SETTING_STUDIO
            ? new StudioEnvironment(this.#renderer, this.#scene)
            : new OutdoorEnvironment(this.#renderer, this.#scene, {
                models: environmentModels,
                onChange: () => this.#requestRender(),
            });
        this.#scene.add(this.#content, this.#scenery, this.#backdrop, this.#decor, this.#lamps);

        this.#controls = new OrbitControls(this.#camera, this.#renderer.domElement);
        this.#controls.enableDamping = !this.#reducedMotion;
        this.#controls.dampingFactor = 0.08;
        this.#controls.maxPolarAngle = Math.PI / 2 - 0.04; // stay above the ground
        // stay on the garden side: the scenery (house, garden) is built to be seen from there
        this.#controls.minAzimuthAngle = -SceneRenderer.MAX_AZIMUTH;
        this.#controls.maxAzimuthAngle = SceneRenderer.MAX_AZIMUTH;
        this.#controls.minDistance = 2;
        this.#controls.maxDistance = SceneRenderer.MAX_DISTANCE;
        // OrbitControls claims every touch (touch-action: none), which traps the page on
        // phones where the view fills half the screen. Leave vertical swipes to the browser
        // (they scroll the page and cancel the drag); horizontal swipes turn, pinch zooms.
        this.#renderer.domElement.style.touchAction = 'pan-y';
        this.#controls.addEventListener('change', () => this.#requestRender());
        this.#controls.addEventListener('start', () => { this.#intro = null; });
        this.#keyboard = new KeyboardNavigation(this.#camera, this.#controls);
        this.#picker = new PartPicker(this.#scene);

        this.#gpuTimer = new GpuTimer(this.#renderer.getContext());
        this.#governor = new PerformanceGovernor(startTier, pinned ? startTier : maxTier, (tier) => this.#applyTier(tier), pinned);
        this.#applyTier(this.#governor.tier);
        this.#resizeObserver = new ResizeObserver(() => this.#resize());
        this.#resizeObserver.observe(container);
        this.#resize();
        // no flicker while the view is scrolled out of sight
        this.#visibilityObserver = new IntersectionObserver(([entry]) => { this.#onScreen = entry.isIntersecting; });
        this.#visibilityObserver.observe(container);
    }

    /** @returns {boolean} whether the key is a navigation key (prevent its default then) */
    keyDown(code) {
        const handled = this.#keyboard.press(code);
        if (handled) {
            this.#intro = null;
            this.#requestRender();
        }

        return handled;
    }

    keyUp(code) {
        return this.#keyboard.release(code);
    }

    releaseKeys() {
        this.#keyboard.releaseAll();
    }

    /**
     * The product part under the pointer (client coordinates), outlined.
     * @returns {{label: string, sku: string}|null}
     */
    pickAt(clientX, clientY) {
        const rect = this.#renderer.domElement.getBoundingClientRect();
        this.#pointer.set(((clientX - rect.left) / rect.width) * 2 - 1, -((clientY - rect.top) / rect.height) * 2 + 1);
        const part = this.#picker.pick(this.#pointer, this.#camera);
        this.#requestRender();

        return part;
    }

    clearPick() {
        this.#picker.clear();
        this.#requestRender();
    }

    get tier() {
        return this.#governor.tier;
    }

    get mood() {
        return this.#environment.mood;
    }

    /** MOOD_DAY, MOOD_SUNSET or MOOD_EVENING outdoors (light, sky, glowing lamps and windows); the studio has one mood. */
    setMood(mood) {
        this.#environment.setMood(mood);
        this.#applyMood();
        this.#requestRender();
    }

    #applyMood() {
        for (const material of this.#materials.values()) {
            this.#applyGlow(material);
        }
        this.#rebuildLamps();
        this.#applyModelLighting();
    }

    get decorVisible() {
        return this.#decorVisible;
    }

    /**
     * What official data says about the site's ground ({infiltration: [{points, potential}]}
     * in metres, from the plan's conditions endpoint), drawn as an overlay that starts hidden.
     * Answers whether there is anything to show.
     */
    setConditions(conditions) {
        this.#conditionsData = conditions;
        const visible = this.#conditions?.visible ?? false;
        this.#conditions?.dispose();
        this.#conditions = new InfiltrationOverlay(conditions ?? {}, this.#terrain);
        this.#conditions.visible = visible;
        this.#scene.add(this.#conditions.object);
        this.#requestRender();

        return !this.#conditions.isEmpty;
    }

    get conditionsVisible() {
        return this.#conditions?.visible ?? false;
    }

    setConditionsVisible(visible) {
        if (!this.#conditions) {
            return;
        }
        this.#conditions.visible = visible;
        this.#requestRender();
    }

    /** Shows or hides the decoration (furniture, fire pit), fading over DECOR_FADE_MS. */
    setDecorVisible(visible) {
        if (visible === this.#decorVisible) {
            return;
        }
        this.#decorVisible = visible;
        this.#decor.visible = true;
        if (this.#reducedMotion) {
            this.#setDecorAlpha(visible ? 1 : 0);
            this.#decor.visible = visible;
        } else {
            this.#decorFade = { from: this.#decorAlpha, to: visible ? 1 : 0, start: performance.now() };
        }
        this.#requestRender();
    }

    /**
     * Starts the flicker timer while a fire burns where it can be seen: light
     * level above zero, decoration shown, no reduced motion. It renders single
     * frames between the on-demand animation loop's runs.
     */
    #updateFlicker() {
        const previous = this.#flickering;
        this.#flickering = [];
        if (!this.#reducedMotion && this.#environment.lightLevel > 0 && this.#decorAlpha > 0) {
            for (const group of [this.#scenery, this.#decor]) {
                group.traverse((object) => {
                    if (object.userData.flicker) {
                        this.#flickering.push(object);
                    }
                });
            }
        }
        if (this.#flickering.length === 0) {
            clearTimeout(this.#flickerTimer);
            this.#flickerTimer = null;
            previous.forEach((object) => this.#steady(object));

            return;
        }
        if (this.#flickerTimer === null) {
            const tick = () => {
                this.#flickerTimer = setTimeout(tick, SceneRenderer.FLICKER_INTERVAL_MS);
                if (document.hidden || !this.#onScreen || this.photoActive) {
                    return;
                }
                this.#stepFlicker(performance.now() / 1000);
                if (!this.#looping) {
                    this.#renderFrame();
                }
            };
            tick();
        }
    }

    /**
     * Layered sines per fire (phase from its position): flames stretch and
     * sway, the light and the embers breathe with them.
     */
    #stepFlicker(time) {
        for (const object of this.#flickering) {
            const phase = object.userData.flickerPhase ??= object.getWorldPosition(new THREE.Vector3()).x * 7.3;
            const t = time + phase;
            const flicker = 0.5 * Math.sin(t * 9.1) + 0.3 * Math.sin(t * 15.7 + 1.3) + 0.2 * Math.sin(t * 23.3 + 2.1);
            if (object.isLight) {
                object.intensity = (object.userData.steadyIntensity ?? 0) * (1 + 0.22 * flicker);
            } else if (object.isMesh && object.userData.flicker === 'flame') {
                object.scale.set(1 + 0.06 * Math.sin(t * 11.3), 1 + 0.16 * flicker, 1 + 0.06 * Math.sin(t * 8.7 + 0.5));
                object.rotation.set(0.04 * Math.sin(t * 5.3), t * 0.35, 0.05 * Math.sin(t * 6.1 + 1));
                // the noise mask rises through the flames: tongues lick upwards
                object.material.alphaMap?.offset.set(0.08 * Math.sin(t * 1.3), -t * 0.9);
            } else if (object.isSprite) {
                object.scale.setScalar((object.userData.baseScale ?? 1) * (1 + 0.08 * flicker));
            } else if (object.isPoints) {
                this.#stepSparks(object, t);
            } else if (object.isMesh && object.material.userData.glow) {
                const glow = object.material.userData.glow;
                const steady = THREE.MathUtils.lerp(glow.dayIntensity, glow.nightIntensity, this.#environment.lightLevel);
                object.material.emissiveIntensity = steady * (1 + 0.15 * flicker);
            }
        }
    }

    /** Sparks rise ~1.2 m in 1.5–3 s, drift sideways and start again at the embers. */
    #stepSparks(points, time) {
        const position = points.geometry.attributes.position;
        points.userData.seeds.forEach(([a, b, c], i) => {
            const life = (time / (1.5 + a * 1.5) + b) % 1;
            const angle = c * Math.PI * 2 + life * 2;
            const spread = 0.05 + life * 0.25;
            position.setXYZ(i, Math.cos(angle) * spread, life * 1.2, Math.sin(angle) * spread);
        });
        position.needsUpdate = true;
    }

    #steady(object) {
        if (object.isLight) {
            object.intensity = object.userData.steadyIntensity ?? object.intensity;
        } else if (object.isMesh && object.userData.flicker === 'flame') {
            object.scale.setScalar(1);
            object.rotation.set(0, 0, 0);
        }
    }

    /** Switches all textured materials between drawn and photographed textures; resolves once loaded. */
    async #setPhotoTextures(on) {
        this.#photoTextures = on;
        const jobs = [];
        for (const material of this.#materials.values()) {
            const name = material.userData.textureName;
            if (!hasPhotoTexture(name)) {
                continue;
            }
            if (on) {
                jobs.push(applyPhotoTexture(material, name));
            } else {
                removePhotoTexture(material);
            }
        }
        await Promise.allSettled(jobs);
        this.#requestRender();
    }

    get photoActive() {
        return this.#photo?.active ?? false;
    }

    /**
     * Marks a stretch of the product, e.g. the run of a fence the customer is
     * editing: a translucent wall from `from` to `to` (plan x/z, metres).
     *
     * @param {{from: number[], to: number[], height: number}} stretch
     */
    highlightStretch({ from, to, height }) {
        if (!this.#highlight) {
            this.#highlight = new THREE.Mesh(
                new THREE.BoxGeometry(1, 1, 1),
                new THREE.MeshBasicMaterial({ color: PartPicker.OUTLINE_COLOUR, transparent: true, opacity: 0.22, depthWrite: false }),
            );
            this.#highlight.name = 'run-highlight';
            this.#highlight.renderOrder = 5;
            this.#scene.add(this.#highlight);
        }
        const dx = to[0] - from[0];
        const dz = to[1] - from[1];
        const top = height + SceneRenderer.HIGHLIGHT_MARGIN;
        this.#highlight.scale.set(Math.hypot(dx, dz) + SceneRenderer.HIGHLIGHT_MARGIN, top, SceneRenderer.HIGHLIGHT_DEPTH);
        this.#highlight.position.set((from[0] + to[0]) / 2, top / 2, (from[1] + to[1]) / 2);
        this.#highlight.rotation.set(0, Math.atan2(-dz, dx), 0);
        this.#highlight.visible = true;
        this.#requestRender();
    }

    clearHighlight() {
        if (this.#highlight?.visible) {
            this.#highlight.visible = false;
            this.#requestRender();
        }
    }

    /**
     * Renders the current view as a photo (path traced, see photo_mode.js).
     * The live view pauses until stopPhoto(); camera controls are off.
     *
     * @param {{ skyUrl: string, onProgress?: (fraction: number) => void, onDone?: () => void }} options
     */
    async startPhoto({ skyUrl, onProgress, onDone }) {
        this.clearHighlight();
        this.#photo ??= new PhotoMode(this.#renderer, this.#scene, this.#camera);
        // the photo is taken from where the camera is heading: finish a running
        // camera flight (intro, reset) and let the orbit damping come to rest
        if (this.#intro) {
            this.#camera.position.copy(this.#intro.to);
            this.#intro = null;
        }
        this.#keyboard.releaseAll();
        const damping = this.#controls.enableDamping;
        this.#controls.enableDamping = false;
        this.#controls.update();
        this.#controls.enableDamping = damping;
        this.#controls.enabled = false;
        this.#looping = false;
        this.#flickering.forEach((object) => this.#steady(object));
        await this.#setPhotoTextures(true);
        await this.#photo.start({
            skyUrl,
            sunDirection: this.#environment.sunDirection,
            skyIntensity: this.#environment.skyIntensity,
            backgroundIntensity: this.#environment.skyBackgroundIntensity,
            samples: this.#environment.photoSamples,
            focusDistance: this.#camera.position.distanceTo(this.#controls.target),
            // boxes drawn with the stone texture (gabion fill) get real stones
            stoneFills: (this.#lastSceneData?.parts ?? [])
                .filter((part) => part.shape === 'box' && this.#materialDefinitions[part.material]?.texture === PhotoMode.STONE_TEXTURE),
            onProgress,
            onDone,
        });
    }

    stopPhoto() {
        if (!this.#photo) {
            return;
        }
        this.#photo.stop();
        this.#setPhotoTextures(Boolean(TIERS[this.#governor.tier].photoTextures));
        this.#controls.enabled = true;
        this.#requestRender();
    }

    /** @returns {Promise<Blob>} the finished photo as PNG */
    photoBlob() {
        return this.#photo.toBlob();
    }

    #stepDecorFade(now) {
        if (!this.#decorFade) {
            return false;
        }
        const { from, to, start } = this.#decorFade;
        const t = Math.min(1, (now - start) / SceneRenderer.DECOR_FADE_MS);
        this.#setDecorAlpha(THREE.MathUtils.lerp(from, to, t * t * (3 - 2 * t)));
        if (t >= 1) {
            this.#decorFade = null;
            this.#decor.visible = to > 0;
        }

        return t < 1;
    }

    /**
     * Fades every decoration material; a material is only transparent while
     * fading (transparent objects are sorted and skip depth writes).
     */
    #setDecorAlpha(alpha) {
        this.#decorAlpha = alpha;
        const seen = new Set();
        this.#decor.traverse((object) => {
            const materials = object.isMesh || object.isSprite || object.isPoints ? [object.material].flat() : [];
            for (const material of materials) {
                if (seen.has(material)) {
                    continue;
                }
                seen.add(material);
                if (!('baseOpacity' in material.userData)) {
                    material.userData.baseOpacity = material.opacity;
                    material.userData.baseTransparent = material.transparent;
                }
                material.opacity = material.userData.baseOpacity * alpha;
                const transparent = alpha < 1 || material.userData.baseTransparent;
                if (material.transparent !== transparent) {
                    material.transparent = transparent;
                    material.needsUpdate = true;
                }
            }
        });
        this.#applyModelLighting();
    }

    /**
     * Models bring their own lighting cues: materials with userData.glow
     * (embers) follow the mood like the scene's materials, objects marked
     * userData.litOnly (flames, the fire's light) appear only when the light
     * level is above zero; their lights fade with the decoration.
     */
    #applyModelLighting() {
        const level = this.#environment.lightLevel;
        const seen = new Set();
        for (const group of [this.#scenery, this.#decor]) {
            const fade = group === this.#decor ? this.#decorAlpha : 1;
            group.traverse((object) => {
                // a light stays in the scene, dark by day (see #rebuildLamps: no recompiles)
                if (object.userData.litOnly && !object.isLight) {
                    object.visible = level > 0;
                }
                if (object.isLight && object.userData.baseIntensity) {
                    const lit = level > 0 || !object.userData.litOnly;
                    object.userData.steadyIntensity = lit ? object.userData.baseIntensity * (0.6 + 0.4 * level) * fade : 0;
                    object.intensity = object.userData.steadyIntensity;
                }
                if (object.isMesh && object.material.userData.glow && !seen.has(object.material)) {
                    seen.add(object.material);
                    this.#applyGlow(object.material);
                }
            });
        }
        this.#updateFlicker();
    }

    /**
     * Emissive colour and strength for the mood's light level: the daytime
     * glow (LEDs) at 0, the full night glow (lit rooms, lamps) at 1, in between
     * at sunset.
     */
    #applyGlow(material) {
        const glow = material.userData.glow;
        if (!glow) {
            return;
        }
        const level = this.#environment.lightLevel;
        const lit = level > 0 && glow.nightColour;
        material.emissive.set(lit ? glow.nightColour : glow.dayColour);
        material.emissiveIntensity = lit ? THREE.MathUtils.lerp(glow.dayIntensity, glow.nightIntensity, level) : glow.dayIntensity;
    }

    /**
     * Light pools (additive discs on the floor) under every lamp, plus up to
     * MAX_LAMP_LIGHTS real point lights spread among them for the furniture.
     * The lights exist in every mood (by day at intensity 0): three.js compiles
     * the number of lights into every shader, so adding them when the sun sets
     * would recompile all materials and freeze the page for seconds.
     */
    #rebuildLamps() {
        this.#lamps.traverse((object) => object.geometry?.dispose());
        this.#lamps.clear();
        const level = this.#environment.lightLevel;
        if (this.#lampPositions.length === 0) {
            return;
        }
        // the pools only read once the sun has gone; at sunset the lights just add warmth
        if (level >= 1) {
            const pool = new THREE.Mesh(new THREE.CircleGeometry(SceneRenderer.LIGHT_POOL_RADIUS, 32).rotateX(-Math.PI / 2), lightPoolMaterial());
            for (const [x, , z] of this.#lampPositions) {
                const disc = pool.clone();
                disc.position.set(x, SceneRenderer.LIGHT_POOL_HEIGHT, z);
                this.#lamps.add(disc);
            }
        }
        const sorted = [...this.#lampPositions].sort((a, b) => a[0] - b[0] || a[2] - b[2]);
        const count = Math.min(SceneRenderer.MAX_LAMP_LIGHTS, sorted.length);
        for (let i = 0; i < count; i++) {
            const [x, y, z] = sorted[Math.floor(((i + 0.5) / count) * sorted.length)];
            // downlights, as built into rafters: nothing shines up through the glass onto the
            // wall; `radius` (read by the photo mode's path tracer) softens their shadows
            const light = new THREE.SpotLight(0xffc98a, SceneRenderer.LAMP_LIGHT_INTENSITY * level, 7, SceneRenderer.LAMP_CONE, 0.7, 2);
            light.position.set(x, y - 0.05, z);
            light.target.position.set(x, 0, z);
            light.radius = SceneRenderer.LAMP_RADIUS;
            this.#lamps.add(light, light.target);
        }
    }

    /** The best tier the user allows; the governor picks the actual one up to it. */
    setMaxTier(maxTier) {
        this.#governor.setMaxTier(maxTier);
        this.#governor.reset(maxTier);
    }

    /** @returns {Promise<Blob>} the live view as it is on screen, PNG */
    viewBlob() {
        this.#renderFrame();
        // the drawing buffer is not preserved: copy it in the same task as the render
        return new Promise((resolve) => this.#renderer.domElement.toBlob(resolve, 'image/png'));
    }

    /** @returns {Promise<Blob>} the product's silhouette in the live view, as an image-edit mask (product_mask.js) */
    async productMaskBlob() {
        try {
            return await ProductMask.render(this.#renderer, this.#scene, this.#camera, this.#content);
        } finally {
            this.#requestRender();
        }
    }

    /** For inspection in the browser console (debug mode only). */
    get debugScene() {
        return this.#scene;
    }

    /** Camera and controls, to set up a view from the console (debug mode). */
    get debugView() {
        return { camera: this.#camera, controls: this.#controls, render: () => this.#requestRender() };
    }

    /** Numbers for the debug overlay. */
    stats() {
        const info = this.#renderer.info;

        return {
            tier: TIERS[this.#governor.tier].name,
            fps: Math.round(this.#fps),
            frameMs: this.#frameMs.toFixed(1),
            gpuMs: this.#gpuTimer.available ? (this.#gpuTimer.latest?.toFixed(1) ?? '…') : 'n/a',
            refreshHz: Math.round(1000 / this.#governor.refreshMs),
            drawCalls: info.render.calls,
            triangles: info.render.triangles,
            pixelRatio: this.#renderer.getPixelRatio(),
            geometries: info.memory.geometries,
            textures: info.memory.textures,
        };
    }

    #applyTier(index) {
        const tier = TIERS[index];
        if (!this.photoActive) {
            this.#setPhotoTextures(Boolean(tier.photoTextures));
        }
        this.#renderer.setPixelRatio(Math.min(window.devicePixelRatio, tier.pixelRatio));
        this.#renderer.shadowMap.type = tier.softShadows ? THREE.PCFSoftShadowMap : THREE.PCFShadowMap;
        this.#environment.setShadowMapSize(tier.shadowMapSize);
        this.#environment.setDetail(tier.detail);
        if (this.#lastSceneData) {
            this.#syncMaterials(); // glass mode depends on the tier
            this.#rebuildContent(this.#lastSceneData.parts ?? []);
        }
        this.#scene.traverse((object) => {
            if (object.material) {
                object.material.needsUpdate = true; // shadow map type is compiled into the shaders
            }
        });
        this.#composer?.dispose();
        const { width, height } = this.#size();
        this.#composer = tier.postProcessing && width > 0
            ? createComposer(this.#renderer, this.#scene, this.#camera, tier, width, height)
            : null;
        this.#resize();
        this.#requestRender();
    }

    /**
     * The real ground of an aerial site (from the plan's terrain endpoint: height grids in
     * metres relative to the origin). The site's draped parts (plot, roads, surfaces), the
     * lawn, the city around it and the overlay follow it; the server already stands the
     * upright objects on it. Answers whether there is any.
     */
    async setTerrain(data) {
        this.#terrain = new TerrainField(data);
        this.#governor.pause(SceneRenderer.SCENERY_SETTLE_MS);
        this.#environment.setTerrain?.(this.#terrain);
        if (this.#lastSceneData) {
            this.#rebuildContent(this.#lastSceneData.parts ?? []);
        }
        if (this.#conditionsData) {
            this.setConditions(this.#conditionsData);
        }
        try {
            await this.#renderer.compileAsync(this.#scene, this.#camera);
        } catch {
            // compiling ahead is an optimisation
        }
        this.#governor.pause(SceneRenderer.SCENERY_SETTLE_MS);
        this.#requestRender();

        return !this.#terrain.isFlat;
    }

    /** metres above the plan's origin at x/z (0 without terrain) */
    groundAt(x, z) {
        return this.#terrain.heightAt(x, z);
    }

    /**
     * The real buildings and streets around an aerial site ({radius, buildings, roads}
     * in metres, from the plan's surroundings endpoint), drawn as context instead of
     * the generic city within their radius.
     */
    async setSurroundings(surroundings) {
        // building the city and compiling its shaders is one-off work: not a reason to lower the quality
        this.#governor.pause(SceneRenderer.SCENERY_SETTLE_MS);
        this.#environment.setSurroundings?.(surroundings);
        try {
            await this.#renderer.compileAsync(this.#scene, this.#camera);
        } catch {
            // compiling ahead is an optimisation: the first frame compiles what is left
        }
        this.#governor.pause(SceneRenderer.SCENERY_SETTLE_MS);
        this.#requestRender();
    }

    update(sceneData) {
        const generation = ++this.#generation;
        this.#lastSceneData = sceneData;
        this.#materialDefinitions = sceneData.materials ?? {};
        this.#syncMaterials();

        const models = (sceneData.parts ?? []).filter((part) => part.shape === 'model');
        this.#updateScenery(models, generation);
        this.#rebuildContent(sceneData.parts ?? []);

        const productBox = new THREE.Box3().setFromObject(this.#content);
        this.#aerial = true === sceneData.environment?.aerial;
        this.#controls.maxDistance = this.#aerial ? SceneRenderer.AERIAL_MAX_DISTANCE : SceneRenderer.MAX_DISTANCE;
        this.#environment.frame(productBox, sceneData.environment ?? {});
        this.#environment.keepClear(sceneData.clear ?? []);
        this.#partFootprints = groundFootprints(sceneData.parts ?? []);
        this.#productCentre = productCentre(productBox);
        this.#environment.setFootprints(this.#partFootprints, this.#productCentre);
        if (!this.#hasFitted) {
            this.fitView({ animate: true });
            this.#hasFitted = true;
        }
        this.#requestRender();
    }

    /**
     * The product's boxes, merged into one mesh per material: a patio roof is
     * ~100 parts but only ~8 materials, so this saves most draw calls in every
     * pass (main, shadow, ambient occlusion).
     */
    #rebuildContent(parts) {
        this.#lampPositions = parts
            .filter((part) => this.#materialDefinitions[part.material]?.lightPool)
            .map((part) => part.position);
        this.#rebuildLamps();
        const product = parts.filter((part) => part.sku !== null && part.shape !== 'model');
        this.#picker?.setParts(product);
        const backdrop = parts.filter((part) => part.sku === null && part.shape !== 'model');
        this.#mergeInto(this.#content, product);
        this.#mergeInto(this.#backdrop, backdrop);
    }

    #mergeInto(group, parts) {
        group.traverse((object) => {
            if (object.isMesh && object.userData.merged) {
                object.geometry.dispose();
            }
        });
        group.clear();

        const byMaterial = new Map();
        for (const part of parts) {
            const material = this.#materials.get(part.material) ?? this.#fallbackMaterial();
            let geometry = this.#partGeometry(part, material);
            if (!geometry) {
                continue;
            }
            if (part.draped) {
                // a ground layer: laid over the terrain (unchanged on flat ground)
                geometry = this.#terrain.drape(geometry);
            }
            if (!byMaterial.has(material)) {
                byMaterial.set(material, []);
            }
            byMaterial.get(material).push(geometry);
        }

        for (const [material, geometries] of byMaterial) {
            const merged = mergeGeometries(geometries.map((geometry) => (geometry.index ? geometry.toNonIndexed() : geometry)), false);
            geometries.forEach((geometry) => geometry.dispose());
            if (!merged) {
                continue;
            }
            const mesh = new THREE.Mesh(merged, material);
            // light passes through glass: it should not cast a solid shadow
            mesh.castShadow = material.userData.castsShadow;
            mesh.receiveShadow = material.userData.receivesShadow;
            mesh.userData.merged = true;
            group.add(mesh);
        }
    }

    /**
     * Moves the camera to a three-quarter view of the whole construction.
     * Low, wide products (a fence) are seen from closer to eye level: the
     * default elevation would look down on them over a field of lawn.
     */
    fitView({ animate = false } = {}) {
        const box = new THREE.Box3().setFromObject(this.#content);
        if (box.isEmpty()) {
            return;
        }
        const direction = this.#overviewDirection(box);
        const centre = box.getCenter(new THREE.Vector3());
        centre.y *= 1.05; // a touch above the middle: the house roof enters the frame
        const distance = this.#fitDistance(box, centre, direction) * (this.#aerial ? SceneRenderer.AERIAL_MARGIN : 1);
        const to = centre.clone().addScaledVector(direction, distance);

        if (animate && !this.#reducedMotion) {
            // swing in from further out and further round
            const from = centre.clone().addScaledVector(
                direction.clone().applyAxisAngle(new THREE.Vector3(0, 1, 0), 0.6),
                distance * 1.5,
            );
            this.#intro = { from, to, start: performance.now() };
            this.#camera.position.copy(from);
        } else {
            this.#intro = null;
            this.#camera.position.copy(to);
        }
        this.#controls.target.copy(centre);
        this.#controls.update();
        this.#requestRender();
    }

    /**
     * How far along VIEW_DIRECTION the camera must stand so every corner of
     * the box lands within FIT_FILL of the view: exact for any shape, so a
     * long low fence fills the view as well as a compact roof does.
     */
    /**
     * Flies the camera to a named view (VIEW_OVERVIEW or a key of
     * VIEW_DIRECTIONS) of the whole product (with its surroundings if asked)
     * or of the parts whose SKU or label contains `focus`. Resolves when the
     * camera has arrived.
     *
     * @returns {Promise<{view: string, focus: string|null, found: boolean}>}
     */
    showView({ view = SceneRenderer.VIEW_OVERVIEW, focus = null, surroundings = false } = {}) {
        const box = focus ? this.#picker.boundsOf(focus) : new THREE.Box3().setFromObject(this.#content);
        if (!focus && surroundings) {
            // the house, paving, the customer's site: everything drawn around the product
            box.union(new THREE.Box3().setFromObject(this.#backdrop));
        }
        if (box.isEmpty()) {
            return Promise.resolve({ view, focus, found: false });
        }
        const direction = SceneRenderer.VIEW_DIRECTIONS[view]?.clone() ?? this.#overviewDirection(box);
        const centre = box.getCenter(new THREE.Vector3());
        const distance = Math.max(this.#fitDistance(box, centre, direction), focus ? SceneRenderer.MIN_FOCUS_DISTANCE : 0);
        const to = centre.clone().addScaledVector(direction, distance);
        if (this.#reducedMotion) {
            this.#intro = null;
            this.#camera.position.copy(to);
            this.#controls.target.copy(centre);
        } else {
            this.#intro = { from: this.#camera.position.clone(), to, targetFrom: this.#controls.target.clone(), targetTo: centre, start: performance.now() };
        }
        this.#controls.update();
        this.#requestRender();

        return new Promise((resolve) => setTimeout(
            () => resolve({ view, focus, found: true }),
            this.#reducedMotion ? 0 : SceneRenderer.INTRO_MS,
        ));
    }

    /** fitView()'s direction: from the front-right, lower for low, wide products. */
    #overviewDirection(box) {
        if (this.#aerial) {
            return SceneRenderer.AERIAL_DIRECTION.clone();
        }
        const size = box.getSize(new THREE.Vector3());
        const flatness = THREE.MathUtils.clamp(size.y / (SceneRenderer.LOW_PRODUCT_RATIO * Math.hypot(size.x, size.z)), SceneRenderer.MIN_ELEVATION_SHARE, 1);

        return SceneRenderer.VIEW_DIRECTION.clone().setY(SceneRenderer.VIEW_DIRECTION.y * flatness).normalize();
    }

    #fitDistance(box, centre, direction) {
        const forward = direction.clone().negate();
        const right = new THREE.Vector3().crossVectors(forward, this.#camera.up).normalize();
        const up = new THREE.Vector3().crossVectors(right, forward);
        const tanY = Math.tan(THREE.MathUtils.degToRad(this.#camera.fov / 2)) * SceneRenderer.FIT_FILL;
        const tanX = tanY * this.#camera.aspect;
        let distance = 0;
        for (const x of [box.min.x, box.max.x]) {
            for (const y of [box.min.y, box.max.y]) {
                for (const z of [box.min.z, box.max.z]) {
                    const offset = new THREE.Vector3(x, y, z).sub(centre);
                    // the corner's depth in front of the camera is distance + offset·forward
                    const depth = offset.dot(forward);
                    distance = Math.max(
                        distance,
                        Math.abs(offset.dot(right)) / tanX - depth,
                        Math.abs(offset.dot(up)) / tanY - depth,
                    );
                }
            }
        }

        return distance;
    }

    dispose() {
        this.#looping = false;
        this.#conditions?.dispose();
        clearTimeout(this.#flickerTimer);
        this.#visibilityObserver.disconnect();
        this.#photo?.dispose();
        this.#highlight?.geometry.dispose();
        this.#highlight?.material.dispose();
        this.#resizeObserver.disconnect();
        this.#controls.dispose();
        this.#composer?.dispose();
        this.#content.clear();
        this.#materials.forEach((material) => material.dispose());
        this.#geometries.forEach((geometry) => geometry.dispose());
        this.#environment.dispose();
        this.#renderer.dispose();
        this.#renderer.domElement.remove();
    }

    /**
     * Loads the scenery models in the background; a newer update() discards
     * the results of an older one. The old scenery stays until the new is
     * ready, so plants do not flicker while dragging a slider.
     */
    async #updateScenery(parts, generation) {
        const results = await Promise.allSettled(parts.map(async (part) => {
            const model = await this.#models.variant(part.url, part.variant, part.size);
            if (model) {
                model.position.fromArray(part.position);
                model.rotation.fromArray(part.rotation);
            }

            return model;
        }));
        if (generation !== this.#generation) {
            return;
        }
        this.#scenery.clear();
        this.#decor.clear();
        results.forEach((result, i) => {
            if (result.status === 'fulfilled' && result.value) {
                (parts[i].decor ? this.#decor : this.#scenery).add(result.value);
            } else if (result.status === 'rejected') {
                console.warn('SceneRenderer: model failed to load', result.reason);
            }
        });
        this.#setDecorAlpha(this.#decorAlpha);
        // see-through light effects stay out of the ambient occlusion pass (post_processing.js)
        const effects = [];
        for (const group of [this.#scenery, this.#decor]) {
            group.traverse((object) => {
                if (object.isSprite || object.isPoints || object.material?.blending === THREE.AdditiveBlending) {
                    effects.push(object);
                }
            });
        }
        this.#scene.userData.withoutAmbientOcclusion = effects;
        // models that cover the ground (the fire pit's gravel ring) keep the grass off
        const covered = [...this.#scenery.children, ...this.#decor.children]
            .filter(coversGround)
            .map((model) => {
                const box = new THREE.Box3().setFromObject(model);

                return { minX: box.min.x, maxX: box.max.x, minZ: box.min.z, maxZ: box.max.z };
            });
        if (covered.length > 0) {
            this.#environment.setFootprints([...this.#partFootprints, ...covered], this.#productCentre);
        }
        this.#requestRender();
    }

    #syncMaterials() {
        const physicalGlass = TIERS[this.#governor.tier].transmission;
        for (const [key, def] of Object.entries(this.#materialDefinitions)) {
            this.#materials.get(key)?.dispose();
            this.#materials.set(key, this.#createMaterial(def, physicalGlass));
        }
    }

    /**
     * @param {boolean} physicalGlass true: transmission (refraction, a second
     *        scene pass); false: transparent and reflective, nearly the same
     *        look for thin panes at a fraction of the cost
     */
    #createMaterial(def, physicalGlass) {
        const glass = def.transmission > 0;
        const fakeGlass = glass && !physicalGlass;
        const opacity = fakeGlass ? THREE.MathUtils.lerp(0.9, 0.18, def.transmission) + def.roughness * 0.35 : def.opacity;
        const material = new THREE.MeshPhysicalMaterial({
            color: def.color,
            roughness: def.roughness,
            metalness: def.metalness,
            clearcoat: def.clearcoat ?? 0,
            clearcoatRoughness: 0.35,
            transmission: fakeGlass ? 0 : def.transmission,
            ior: def.ior ?? 1.5,
            thickness: glass ? 0.01 : 0,
            opacity: Math.min(1, opacity),
            transparent: opacity < 1,
            depthWrite: opacity >= 1 || (glass && !fakeGlass),
            side: glass || def.opacity < 1 ? THREE.DoubleSide : THREE.FrontSide,
            specularIntensity: glass ? 1 : 0.5,
            envMapIntensity: fakeGlass ? 1.15 : 1,
        });
        material.userData.isGlass = glass;
        material.userData.castsShadow = !glass && def.opacity >= 0.6;
        material.userData.receivesShadow = !glass;
        if (def.emissive > 0 || def.nightGlow) {
            material.userData.glow = {
                dayColour: def.color,
                dayIntensity: def.emissive ?? 0,
                nightColour: def.nightGlow,
                nightIntensity: def.nightGlowIntensity ?? 0,
            };
            this.#applyGlow(material);
        }
        const texture = def.texture ? proceduralTexture(def.texture) : null;
        if (texture) {
            material.map = texture.map;
            if (texture.bumpScale > 0) {
                material.bumpMap = texture.bumpMap;
                material.bumpScale = texture.bumpScale;
            }
            if (def.nightGlow) {
                // a lit room glows with its pattern, not as a flat colour; a façade only in its lit windows
                material.emissiveMap = texture.glowMap ?? texture.map;
            }
        }
        material.userData.textureSize = texture ? def.textureSize : null;
        material.userData.textureName = def.texture ?? null;
        if (this.#photoTextures && hasPhotoTexture(def.texture)) {
            applyPhotoTexture(material, def.texture).then(() => this.#requestRender());
        }

        return material;
    }

    /** A world-space copy of the part's geometry, ready to merge. */
    #partGeometry(part, material) {
        let geometry;
        if (part.shape === 'box') {
            geometry = this.#boxGeometry(part.size, material).clone();
        } else if (part.shape === 'extrusion') {
            geometry = this.#extrusionGeometry(part.profile, part.depth, material, isLyingFlat(part.rotation));
        } else if (part.shape === 'wire_mesh') {
            geometry = this.#wireMeshGeometry(part, material).clone();
        } else {
            console.warn(`SceneRenderer: unsupported shape "${part.shape}"`);
            return null;
        }

        return geometry.applyMatrix4(new THREE.Matrix4().compose(
            new THREE.Vector3().fromArray(part.position),
            new THREE.Quaternion().setFromEuler(new THREE.Euler().fromArray(part.rotation)),
            new THREE.Vector3(1, 1, 1),
        ));
    }

    /** @param {boolean} upright standing on its outline (a building footprint, paving): walls get UVs along them */
    #extrusionGeometry(profile, depth, material, upright) {
        const shape = new THREE.Shape(profile.map(([x, y]) => new THREE.Vector2(x, y)));
        const geometry = new THREE.ExtrudeGeometry(shape, { depth, bevelEnabled: false }).translate(0, 0, -depth / 2);
        geometry.deleteAttribute('uv');
        geometry.setAttribute('uv', new THREE.BufferAttribute(new Float32Array(geometry.attributes.position.count * 2), 2));
        const textureSize = material.userData.textureSize;
        if (!textureSize) {
            return geometry;
        }

        if (!upright) {
            return applyMetricUVs(geometry, textureSize);
        }
        const [x, y] = profile[0] ?? [0, 0];

        return applyUprightUVs(geometry, depth, textureSize, Math.round(x * 7919 + y * 104729));
    }

    /** Mats of one size share their geometry: a fence is mostly identical full-width mats. */
    #wireMeshGeometry(part, material) {
        const textureSize = material.userData.textureSize;
        const key = `mesh|${part.size.join('|')}|${part.pitch}|${part.rowSpacing}|${part.wires.join('|')}|${part.doubleBars}|${part.overhang}|${textureSize}`;
        if (!this.#geometries.has(key)) {
            const geometry = wireMeshGeometry(part);
            this.#geometries.set(key, textureSize ? applyMetricUVs(geometry, textureSize) : geometry);
        }

        return this.#geometries.get(key);
    }

    /** Geometries are shared between parts of the same size and texture scale (all rafters, all posts). */
    #boxGeometry([x, y, z], material) {
        const textureSize = material.userData.textureSize;
        const rounded = !material.userData.isGlass && Math.min(x, y, z) >= SceneRenderer.MIN_THICKNESS_FOR_ROUNDING;
        const key = `${x}|${y}|${z}|${textureSize}|${rounded}`;
        if (!this.#geometries.has(key)) {
            const geometry = rounded
                ? new RoundedBoxGeometry(x, y, z, 2, Math.min(SceneRenderer.EDGE_RADIUS, Math.min(x, y, z) / 4))
                : new THREE.BoxGeometry(x, y, z);
            this.#geometries.set(key, textureSize ? applyMetricUVs(geometry, textureSize) : geometry);
        }

        return this.#geometries.get(key);
    }

    #fallbackMaterial() {
        if (!this.#materials.has('')) {
            this.#materials.set('', new THREE.MeshStandardMaterial({ color: 0x999999 }));
        }

        return this.#materials.get('');
    }

    #size() {
        return { width: this.#container.clientWidth, height: this.#container.clientHeight };
    }

    #resize() {
        const { width, height } = this.#size();
        if (width === 0 || height === 0) {
            return;
        }
        this.#renderer.setSize(width, height, false);
        if (this.#composer) {
            this.#composer.setPixelRatio(this.#renderer.getPixelRatio());
            this.#composer.setSize(width, height);
        } else if (TIERS[this.#governor?.tier ?? 0]?.postProcessing) {
            this.#applyTier(this.#governor.tier); // the container had no size when the tier was applied
            return;
        }
        this.#camera.aspect = width / height;
        this.#camera.updateProjectionMatrix();
        this.#requestRender();
    }

    /** Keeps a frame loop alive while something moves (damping, intro), then stops it. */
    #requestRender() {
        if (this.photoActive) {
            return; // the path tracer owns the canvas
        }
        this.#keepRenderingUntil = performance.now() + SceneRenderer.KEEP_RENDERING_MS;
        if (this.#looping) {
            return;
        }
        this.#looping = true;
        // a loop that restarts right after the last frame is the same motion going on (on a slow
        // device every frame outlasts KEEP_RENDERING_MS): keep measuring, or slowness is never seen
        const now = performance.now();
        this.#measuring = now - this.#lastFrameDrawn < SceneRenderer.RESUME_MS;
        this.#lastFrame = this.#measuring ? this.#lastFrameDrawn : now;
        const frame = (now) => {
            if (!this.#looping) {
                return;
            }
            const delta = now - this.#lastFrame;
            // cap the step, so a stalled tab does not jump the camera
            const seconds = Math.min(0.05, delta / 1000);
            this.#lastFrame = now;
            if (this.#measuring) {
                this.#frameMs += (delta - this.#frameMs) * SceneRenderer.FPS_SMOOTHING;
                this.#fps = 1000 / this.#frameMs;
                this.#governor.sample(delta, this.#gpuTimer.latest);
            }
            this.#measuring = true;

            const introRunning = this.#stepIntro(now);
            const fading = this.#stepDecorFade(now);
            const walking = this.#keyboard.step(seconds);
            const moving = this.#controls.update() || walking;
            this.#updateDepthAndHaze();
            this.#renderFrame();
            this.#lastFrameDrawn = performance.now();
            if (introRunning || fading || moving || now < this.#keepRenderingUntil) {
                requestAnimationFrame(frame);
            } else {
                this.#looping = false;
            }
        };
        requestAnimationFrame(frame);
    }

    #updateDepthAndHaze() {
        const distance = this.#camera.position.distanceTo(this.#controls.target);
        this.#environment.setFogOffset?.(this.#aerial ? distance : 0);
        const near = THREE.MathUtils.clamp(distance * SceneRenderer.NEAR_PER_DISTANCE, SceneRenderer.MIN_NEAR, SceneRenderer.MAX_NEAR);
        if (Math.abs(near - this.#camera.near) > near * 0.05) {
            this.#camera.near = near;
            this.#camera.updateProjectionMatrix();
        }
    }

    #renderFrame() {
        this.#renderer.info.reset();
        this.#gpuTimer.begin();
        if (this.#composer) {
            this.#composer.render();
        } else {
            this.#renderer.render(this.#scene, this.#camera);
        }
        this.#gpuTimer.end();
        if (this.#renderer.domElement.style.opacity === '0') {
            this.#renderer.domElement.style.opacity = '1';
        }
    }

    #stepIntro(now) {
        if (!this.#intro) {
            return false;
        }
        const t = Math.min(1, (now - this.#intro.start) / SceneRenderer.INTRO_MS);
        const eased = 1 - (1 - t) ** 3;
        this.#camera.position.lerpVectors(this.#intro.from, this.#intro.to, eased);
        if (this.#intro.targetTo) {
            // showView(): the point looked at moves along
            this.#controls.target.lerpVectors(this.#intro.targetFrom, this.#intro.targetTo, eased);
        }
        if (t >= 1) {
            this.#intro = null;
        }

        return t < 1;
    }
}

/** Ground contact height: parts whose underside is below this stand on the ground. */
const GROUND_CONTACT = 0.15;
const FOOTPRINT_MARGIN = 0.12;

/**
 * Axis-aligned outlines (x/z, metres) of the boxes standing on the ground:
 * terrace, walls, posts. Rotation is ignored; good enough to keep grass out.
 */
function groundFootprints(parts) {
    const boxes = parts
        .filter((part) => part.shape === 'box' && part.position[1] - part.size[1] / 2 < GROUND_CONTACT)
        .map((part) => {
            const [x, , z] = part.position;
            const halfX = part.size[0] / 2 + FOOTPRINT_MARGIN;
            const halfZ = part.size[2] / 2 + FOOTPRINT_MARGIN;

            return { minX: x - halfX, maxX: x + halfX, minZ: z - halfZ, maxZ: z + halfZ };
        });
    // outlines lying flat on the ground (paving, prisms of the customer's site): profile x/y is plan x/z
    const flat = parts
        .filter((part) => part.shape === 'extrusion' && isLyingFlat(part.rotation) && part.position[1] - part.depth / 2 < GROUND_CONTACT)
        .map((part) => {
            const xs = part.profile.map(([x]) => x + part.position[0]);
            const zs = part.profile.map(([, y]) => y + part.position[2]);

            return { minX: Math.min(...xs) - FOOTPRINT_MARGIN, maxX: Math.max(...xs) + FOOTPRINT_MARGIN, minZ: Math.min(...zs) - FOOTPRINT_MARGIN, maxZ: Math.max(...zs) + FOOTPRINT_MARGIN };
        });

    return [...boxes, ...flat];
}

/** An extrusion turned a quarter about x and nothing else: its profile lies in plan (Slab::onGround()). */
function isLyingFlat([x, y, z]) {
    return Math.abs(x - Math.PI / 2) < 1e-3 && Math.abs(y) < 1e-6 && Math.abs(z) < 1e-6;
}

function productCentre(box) {
    if (box.isEmpty()) {
        return new THREE.Vector2();
    }
    const centre = box.getCenter(new THREE.Vector3());

    return new THREE.Vector2(centre.x, centre.z);
}

let poolMaterial = null;

/** A soft warm disc, added on top of the floor: light falling from a lamp above. */
function lightPoolMaterial() {
    if (!poolMaterial) {
        const canvas = document.createElement('canvas');
        canvas.width = canvas.height = 128;
        const ctx = canvas.getContext('2d');
        const gradient = ctx.createRadialGradient(64, 64, 0, 64, 64, 64);
        gradient.addColorStop(0, 'rgba(255,214,160,0.55)');
        gradient.addColorStop(0.35, 'rgba(255,190,120,0.22)');
        gradient.addColorStop(1, 'rgba(255,170,90,0)');
        ctx.fillStyle = gradient;
        ctx.fillRect(0, 0, 128, 128);
        const texture = new THREE.CanvasTexture(canvas);
        texture.colorSpace = THREE.SRGBColorSpace;
        poolMaterial = new THREE.MeshBasicMaterial({
            map: texture,
            transparent: true,
            blending: THREE.AdditiveBlending,
            depthWrite: false,
            fog: false,
        });
    }

    return poolMaterial;
}
