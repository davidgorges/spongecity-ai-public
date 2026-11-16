import * as THREE from 'three';
import { RoomEnvironment } from 'three/examples/jsm/environments/RoomEnvironment.js';

export const MOOD_STUDIO = 'studio';

/**
 * A photo studio around the product, for products that do not stand in a
 * garden (scene `setting: studio`): a seamless light floor that fades into
 * the backdrop, a key light front-right whose shadows follow the product, a
 * soft fill and a neutral room for reflections. One mood; nothing to grow,
 * no lamps.
 *
 * Same interface as OutdoorEnvironment, so the renderer and photo mode work
 * with either (photo mode lights the studio with its HDRI, sky_studio.hdr).
 */
export class StudioEnvironment {
    static BACKDROP = 0xe8e6e2;
    static KEY_DISTANCE = 30;
    /** key light: elevation and azimuth from +z towards +x, degrees */
    static KEY_ELEVATION = 48;
    static KEY_AZIMUTH = 40;
    static FLOOR_RADIUS = 400;
    /** the floor fades into the backdrop between these multiples of the product's size */
    static FADE_NEAR = 2.5;
    static FADE_FAR = 7;
    /** photo mode: brightness of the HDRI's cove seen behind the product */
    static PHOTO_BACKDROP = 2.6;

    #renderer;
    #scene;
    #key;
    #fill;
    #floor;
    #keyDirection = new THREE.Vector3();
    #environmentMap;
    #frameBox = null;

    constructor(renderer, scene) {
        this.#renderer = renderer;
        this.#scene = scene;
        scene.background = new THREE.Color(StudioEnvironment.BACKDROP);
        scene.fog = new THREE.Fog(StudioEnvironment.BACKDROP, 20, 60);

        this.#keyDirection.setFromSphericalCoords(
            1,
            THREE.MathUtils.degToRad(90 - StudioEnvironment.KEY_ELEVATION),
            THREE.MathUtils.degToRad(StudioEnvironment.KEY_AZIMUTH),
        );
        this.#key = new THREE.DirectionalLight(0xfff6ec, 2.4);
        this.#key.castShadow = true;
        this.#key.shadow.bias = -0.0003;
        this.#key.shadow.normalBias = 0.015;
        this.#key.shadow.radius = 6;
        this.#fill = new THREE.HemisphereLight(0xf4f6fa, 0xd8d4cc, 0.6);
        scene.add(this.#key, this.#key.target, this.#fill);

        this.#floor = new THREE.Mesh(
            new THREE.CircleGeometry(StudioEnvironment.FLOOR_RADIUS, 64),
            new THREE.MeshStandardMaterial({ color: StudioEnvironment.BACKDROP, roughness: 0.92 }),
        );
        this.#floor.rotation.x = -Math.PI / 2;
        this.#floor.receiveShadow = true;
        this.#floor.name = 'studio-floor';
        scene.add(this.#floor);

        const pmrem = new THREE.PMREMGenerator(renderer);
        const room = new RoomEnvironment();
        this.#environmentMap = pmrem.fromScene(room, 0.04).texture;
        pmrem.dispose();
        room.dispose?.();
        scene.environment = this.#environmentMap;
        scene.environmentIntensity = 0.55;
        renderer.toneMappingExposure = 1.0;
    }

    get mood() {
        return MOOD_STUDIO;
    }

    /** Photo mode: the studio HDRI lights the scene as it is. */
    get skyIntensity() {
        return 1;
    }

    /** The cove behind the product as bright as the lit floor in front of it (light unchanged). */
    get skyBackgroundIntensity() {
        return StudioEnvironment.PHOTO_BACKDROP;
    }

    /** Unit vector towards the key light (photo mode turns its HDRI's key there). */
    get sunDirection() {
        return this.#keyDirection.clone();
    }

    /** Lamps never glow in the studio. */
    /** The studio is evenly lit: the photo's default samples are enough. */
    get photoSamples() {
        return null;
    }

    get lightLevel() {
        return 0;
    }

    /** There is one mood. */
    setMood() {}

    setDetail() {}

    setFootprints() {}

    /** Nothing of the studio stands in the way. */
    keepClear() {}

    setShadowMapSize(size) {
        if (this.#key.shadow.mapSize.x === size) {
            return;
        }
        this.#key.shadow.mapSize.set(size, size);
        this.#key.shadow.map?.dispose();
        this.#key.shadow.map = null;
        if (this.#frameBox) {
            this.frame(this.#frameBox);
        }
    }

    /** Points the key light at the product, fits its shadows and the floor's fade to its size. */
    frame(box) {
        if (box.isEmpty()) {
            return;
        }
        this.#frameBox = box.clone();
        const centre = box.getCenter(new THREE.Vector3());
        const size = Math.max(box.getSize(new THREE.Vector3()).length(), 1);
        const extent = size * 0.9;
        const camera = this.#key.shadow.camera;

        this.#key.target.position.copy(centre);
        this.#key.position.copy(centre).addScaledVector(this.#keyDirection, StudioEnvironment.KEY_DISTANCE);
        camera.left = camera.bottom = -extent;
        camera.right = camera.top = extent;
        camera.near = StudioEnvironment.KEY_DISTANCE - extent * 2;
        camera.far = StudioEnvironment.KEY_DISTANCE + extent * 2;
        camera.updateProjectionMatrix();

        this.#floor.position.set(centre.x, 0, centre.z);
        this.#scene.fog.near = size * StudioEnvironment.FADE_NEAR;
        this.#scene.fog.far = size * StudioEnvironment.FADE_FAR;
    }

    dispose() {
        this.#floor.geometry.dispose();
        this.#floor.material.dispose();
        this.#environmentMap.dispose();
        this.#key.shadow.map?.dispose();
    }
}
