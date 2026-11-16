import * as THREE from 'three';
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js';
import { MeshoptDecoder } from 'three/examples/jsm/libs/meshopt_decoder.module.js';
import { PROCEDURAL_PREFIX, buildProceduralLibrary } from './procedural_models.js';

/**
 * Loads model libraries (one GLB, one node per variant; see
 * app:configurator:import-model) once and hands out variants scaled to a
 * target size. Clones share geometry and materials with the library.
 */
export class ModelLibrary {
    #loader = new GLTFLoader().setMeshoptDecoder(MeshoptDecoder);
    #libraries = new Map();

    /**
     * @param {string} url
     * @param {string} variant node name, e.g. "bush_04"
     * @param {number[]} size target [width, height, depth] in metres; 0 = free
     * @returns {Promise<THREE.Object3D|null>} the variant standing on its origin, or null if missing
     */
    async variant(url, variant, size) {
        const library = await this.#library(url);
        const source = library.getObjectByName(variant);
        if (!source) {
            console.warn(`ModelLibrary: no variant "${variant}" in ${url}`);
            return null;
        }
        // the variant node's own transform stays (meshopt quantisation stores an offset and
        // scale there); the wrapper is what the scene positions, turns and scales
        const model = new THREE.Group();
        model.name = variant;
        model.add(source.clone());

        const extent = new THREE.Box3().setFromObject(model).getSize(new THREE.Vector3()).toArray();
        const ratios = size.map((target, i) => (target > 0 && extent[i] > 0 ? target / extent[i] : Infinity));
        const scale = Math.min(...ratios);
        model.scale.setScalar(Number.isFinite(scale) ? scale : 1);

        return model;
    }

    #library(url) {
        if (!this.#libraries.has(url) && url.startsWith(PROCEDURAL_PREFIX)) {
            const library = buildProceduralLibrary(url);
            this.#libraries.set(url, library ? Promise.resolve(library) : Promise.reject(new Error(`Unknown procedural library ${url}`)));
        }
        if (!this.#libraries.has(url)) {
            this.#libraries.set(url, this.#loader.loadAsync(url).then((gltf) => {
                gltf.scene.traverse((object) => {
                    if (object.isMesh) {
                        object.castShadow = true;
                        object.receiveShadow = true;
                    }
                });

                return gltf.scene;
            }));
        }

        return this.#libraries.get(url);
    }
}
