import * as THREE from 'three';
import { Sky } from 'three/examples/jsm/objects/Sky.js';
import { CloudLayer } from './cloud_layer.js';
import { Garden } from './garden.js';
import { Landscape } from './landscape.js';
import { Lawn } from './lawn.js';

export const MOOD_DAY = 'day';
export const MOOD_SUNSET = 'sunset';
export const MOOD_EVENING = 'evening';
export const MOODS_IN_ORDER = [MOOD_DAY, MOOD_SUNSET, MOOD_EVENING];

/**
 * Light settings per mood. Azimuth is measured from +z (the garden side)
 * towards +x: the sun stands front-right of the façade.
 */
const MOODS = {
    [MOOD_DAY]: {
        sunElevation: 36, sunAzimuth: 52, sunColour: 0xffe8c8, sunIntensity: 3.0,
        turbidity: 2.6, rayleigh: 1.7, mie: 0.003,
        fillSky: 0xe3ecff, fillGround: 0x6b7448, fillIntensity: 0.22,
        environmentIntensity: 0.75, haze: 0xbfcfdc, fogNear: 70, fogFar: 520, exposure: 0.95,
        cloudLit: 0xffffff, cloudShade: 0xb3bfd0, cloudCoverage: 0.46, mountainTint: 0xffffff,
        lights: 0,
        // photo mode: the sky's light (brighter than a real sky, so shade stays as open as in the
        // live view, which lifts it with fill lights), its look, and the path tracer's samples (the darker the mood, the more lamp and window
        // light arrives by chance through bounces, the more samples it takes to settle)
        photoSky: 0.6, photoBackground: 0.8, photoSamples: 32,
    },
    // golden hour: low sun from the side, long warm shadows, glowing sky; lights just coming on
    [MOOD_SUNSET]: {
        sunElevation: 6, sunAzimuth: 68, sunColour: 0xff8a3d, sunIntensity: 2.8,
        turbidity: 7, rayleigh: 2.6, mie: 0.007,
        fillSky: 0x8a86c4, fillGround: 0x3d3128, fillIntensity: 0.28,
        environmentIntensity: 0.55, haze: 0xe2a98f, fogNear: 45, fogFar: 380, exposure: 0.95,
        cloudLit: 0xffa66b, cloudShade: 0x7f6f96, cloudCoverage: 0.52, mountainTint: 0xb98f9a,
        lights: 0.25,
        // a low sun gives little light: the glowing sky does most of the lighting
        photoSky: 0.55, photoBackground: 0.65, photoSamples: 48,
    },
    // low warm sun, deep blue sky: the moment lamps make sense
    [MOOD_EVENING]: {
        sunElevation: 1.5, sunAzimuth: 80, sunColour: 0xff7a3a, sunIntensity: 0.7,
        turbidity: 10, rayleigh: 3.2, mie: 0.006,
        fillSky: 0x5d6fa8, fillGround: 0x2a2620, fillIntensity: 0.35,
        environmentIntensity: 0.4, haze: 0x8f8a99, fogNear: 30, fogFar: 320, exposure: 1.05,
        cloudLit: 0xffa070, cloudShade: 0x5a5470, cloudCoverage: 0.5, mountainTint: 0x6f6680,
        lights: 1,
        photoSky: 0.3, photoBackground: 0.15, photoSamples: 64,
    },
};

/**
 * The world around the product: physically based sky (Preetham), a sun whose
 * shadows follow the product's size, lawn and landscape fading into haze, and
 * the same sky as image-based lighting, so glass and coatings reflect it.
 * Three moods: a sunny afternoon, golden-hour sunset and a warm evening (setMood).
 */
export class OutdoorEnvironment {
    static SUN_DISTANCE = 30;
    /** with the fog pushed back, it also thins out more slowly */
    static FOG_FAR_STRETCH = 1.5;
    #fogOffset = 0;
    static HAZE_COLOR = MOODS[MOOD_DAY].haze;

    #renderer;
    #scene;
    #sky;
    #clouds = new CloudLayer();
    #sun;
    #fill;
    #sunDirection = new THREE.Vector3();
    #environmentMap;
    #landscape;
    #lawn;
    #garden;
    #mood = null;
    #hints = {};
    #frameBox = null;

    /**
     * @param {{ models?: Record<string, string>, onChange?: () => void }} options
     *        model library URLs for the environment (garden_trees), and a callback
     *        for content that arrives later (loaded models)
     */
    constructor(renderer, scene, { models = {}, onChange = () => {} } = {}) {
        this.#renderer = renderer;
        this.#scene = scene;
        this.#sky = this.#createSky(3000);
        scene.add(this.#sky, this.#clouds.mesh);
        scene.fog = new THREE.Fog(OutdoorEnvironment.HAZE_COLOR, 70, 520);

        this.#sun = new THREE.DirectionalLight(0xffffff, 1);
        this.#sun.castShadow = true;
        this.#sun.shadow.bias = -0.0003;
        this.#sun.shadow.normalBias = 0.015;
        this.#sun.shadow.radius = 3;
        // cool fill from the sky side, so shadows are not black
        this.#fill = new THREE.HemisphereLight(0xffffff, 0xffffff, 0.2);
        scene.add(this.#sun, this.#sun.target, this.#fill);

        this.#lawn = new Lawn(scene);
        this.#garden = new Garden(scene, { treeUrl: models.garden_trees ?? null, onChange });
        this.#landscape = new Landscape(scene, OutdoorEnvironment.HAZE_COLOR);
        this.setMood(MOOD_DAY);
    }

    get mood() {
        return this.#mood;
    }

    /**
     * Strength of the photographed sky in photo mode. The skies are normalised to the same
     * average brightness, so this sets the mood: bright day, dimmer sunset, dark dusk.
     */
    get skyIntensity() {
        return MOODS[this.#mood].photoSky;
    }

    /** How bright the sky itself looks in a photo (its light is skyIntensity). */
    get skyBackgroundIntensity() {
        return MOODS[this.#mood].photoBackground;
    }

    /** How many samples the photo of this mood needs to come out clean. */
    get photoSamples() {
        return MOODS[this.#mood].photoSamples;
    }

    /** Unit vector towards the sun of the current mood. */
    get sunDirection() {
        return this.#sunDirection.clone();
    }

    /** How far lamps and lit windows are on in the current mood: 0 (day) to 1 (evening). */
    get lightLevel() {
        return MOODS[this.#mood].lights;
    }

    setMood(mood) {
        if (mood === this.#mood || !MOODS[mood]) {
            return;
        }
        this.#mood = mood;
        const settings = MOODS[mood];
        this.#sunDirection.setFromSphericalCoords(
            1,
            THREE.MathUtils.degToRad(90 - settings.sunElevation),
            THREE.MathUtils.degToRad(settings.sunAzimuth),
        );
        this.#applySkyUniforms(this.#sky, settings);
        this.#clouds.setLighting(this.#sunDirection, settings.cloudLit, settings.cloudShade, settings.cloudCoverage);
        this.#landscape?.setTint(settings.mountainTint);
        this.#landscape?.setLightLevel(settings.lights);
        this.#sun.color.set(settings.sunColour);
        this.#sun.intensity = settings.sunIntensity;
        this.#fill.color.set(settings.fillSky);
        this.#fill.groundColor.set(settings.fillGround);
        this.#fill.intensity = settings.fillIntensity;
        this.#scene.fog.color.set(settings.haze);
        this.#applyFog();
        this.#renderer.toneMappingExposure = settings.exposure;

        this.#environmentMap?.dispose();
        this.#scene.environment = this.#environmentMap = this.#bakeEnvironment(this.#renderer);
        this.#scene.environmentIntensity = settings.environmentIntensity;
        if (this.#frameBox) {
            this.frame(this.#frameBox);
        }
    }

    /** The real ground of an aerial site (see SceneRenderer#setTerrain). */
    setTerrain(terrain) {
        this.#lawn.setTerrain(terrain);
        this.#landscape.setTerrain(terrain);
    }

    /** The real city around an aerial site (see SceneRenderer#setSurroundings). */
    setSurroundings(surroundings) {
        this.#landscape.setSurroundings(surroundings);
    }

    /**
     * Pushes the haze back by this many metres: seen from far above (aerial view), it
     * should begin behind the site the camera looks at, not between the camera and it.
     */
    setFogOffset(metres) {
        if (Math.abs(metres - this.#fogOffset) < 1) {
            return;
        }
        this.#fogOffset = metres;
        this.#applyFog();
    }

    #applyFog() {
        const settings = MOODS[this.#mood];
        if (!settings) {
            return;
        }
        this.#scene.fog.near = settings.fogNear + this.#fogOffset;
        this.#scene.fog.far = settings.fogFar + this.#fogOffset * OutdoorEnvironment.FOG_FAR_STRETCH;
    }

    /**
     * Areas the garden's own hedges, trees and neighbouring houses leave free:
     * the customer's site (scene `clear`, rectangles in metres).
     *
     * @param {number[][]} areas [minX, maxX, minZ, maxZ]
     */
    keepClear(areas) {
        const rectangles = areas.map(([minX, maxX, minZ, maxZ]) => ({ minX, maxX, minZ, maxZ }));
        this.#garden.keepClear(rectangles);
        this.#landscape.keepClear(rectangles);
    }

    /** Density of distant scenery for a quality tier's detail level (0–3). */
    setDetail(detail) {
        this.#landscape.setDetail(detail);
        this.#lawn.setDetail(detail);
        this.#garden.setDetail(detail);
    }

    /** Outlines on the ground where no grass grows, and the product's centre. */
    setFootprints(footprints, centre) {
        this.#lawn.setFootprints([...footprints, ...this.#garden.footprints], centre);
    }

    setShadowMapSize(size) {
        if (this.#sun.shadow.mapSize.x === size) {
            return;
        }
        this.#sun.shadow.mapSize.set(size, size);
        this.#sun.shadow.map?.dispose();
        this.#sun.shadow.map = null;
        if (this.#frameBox) {
            this.frame(this.#frameBox);
        }
    }

    /**
     * Points the sun at the product and fits its shadow frustum tightly around it.
     *
     * @param {{gardenPath?: boolean}} hints the scene's `environment` (docs/configurator/scene-format.md)
     */
    frame(box, hints = this.#hints) {
        if (box.isEmpty()) {
            return;
        }
        this.#frameBox = box.clone();
        this.#hints = hints;
        // a real site seen from above lies in a city, a garden in the countryside
        this.#landscape.setUrban(hints.aerial === true, hints.suburban === true);
        this.#lawn.setUrban(hints.aerial === true);
        this.#garden.layout(box, { path: hints.gardenPath !== false });
        const centre = box.getCenter(new THREE.Vector3());
        // with a big shadow map the frustum can reach the house's eaves (their shadow band on the façade)
        const reach = this.#sun.shadow.mapSize.x >= 4096 ? 0.95 : 0.65;
        const extent = box.getSize(new THREE.Vector3()).length() * reach;
        const camera = this.#sun.shadow.camera;

        this.#sun.target.position.copy(centre);
        this.#sun.position.copy(centre).addScaledVector(this.#sunDirection, OutdoorEnvironment.SUN_DISTANCE);
        camera.left = camera.bottom = -extent;
        camera.right = camera.top = extent;
        camera.near = OutdoorEnvironment.SUN_DISTANCE - extent * 2;
        camera.far = OutdoorEnvironment.SUN_DISTANCE + extent * 2;
        camera.updateProjectionMatrix();
    }

    dispose() {
        this.#clouds.dispose();
        this.#garden.dispose();
        this.#landscape.dispose();
        this.#lawn.dispose();
        this.#environmentMap.dispose();
        this.#sun.shadow.map?.dispose();
    }

    #createSky(scale) {
        const sky = new Sky();
        sky.scale.setScalar(scale);
        sky.material.uniforms.mieDirectionalG.value = 0.82;

        return sky;
    }

    #applySkyUniforms(sky, settings) {
        const uniforms = sky.material.uniforms;
        uniforms.turbidity.value = settings.turbidity;
        uniforms.rayleigh.value = settings.rayleigh;
        uniforms.mieCoefficient.value = settings.mie;
        uniforms.sunPosition.value.copy(this.#sunDirection);
    }

    /** Renders the sky (plus a lawn-coloured floor for the lower half) into a prefiltered environment map. */
    #bakeEnvironment(renderer) {
        const envScene = new THREE.Scene();
        const sky = this.#createSky(50);
        this.#applySkyUniforms(sky, MOODS[this.#mood]);
        envScene.add(sky);
        // the same clouds in reflections, scaled into the bake's small world
        const clouds = this.#clouds.mesh.clone();
        clouds.scale.setScalar(0.04);
        clouds.position.y = 30;
        envScene.add(clouds);
        const floor = new THREE.Mesh(
            new THREE.CircleGeometry(40, 32),
            new THREE.MeshBasicMaterial({ color: 0x6a705c }), // muted: reflections of the ground should not look green
        );
        floor.rotation.x = -Math.PI / 2;
        floor.position.y = -1;
        envScene.add(floor);

        const pmrem = new THREE.PMREMGenerator(renderer);
        const target = pmrem.fromScene(envScene, 0.02, 0.1, 100);
        pmrem.dispose();
        floor.geometry.dispose();
        floor.material.dispose();
        sky.geometry.dispose();
        sky.material.dispose();

        return target.texture;
    }
}
