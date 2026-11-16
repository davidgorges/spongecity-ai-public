import * as THREE from 'three';

/**
 * Photographed PBR textures (CC0, Poly Haven) that replace the drawn canvas
 * textures of procedural_textures.js on the ultra tier and in photo mode:
 * colour, normal and roughness maps at real-world scale. The URLs come from
 * the server (versioned AssetMapper paths, see ConfiguratorExtension::textures());
 * the files are only downloaded when a material first needs them.
 *
 * Keyed by the procedural texture name (Core\Configurator\Scene\ProceduralTexture).
 * `size` is the edge length in metres one texture image covers.
 */
const SETS = {
    // colour map reduced to neutral fine grain: the material keeps its own colour
    plaster: { size: 2, normalScale: 0.8, tint: 0 },
    paving: { size: 3, normalScale: 1, tint: 0.65 },
    roof_tiles: { size: 3, normalScale: 1, tint: 0.4 },
};

let urls = {};
const loads = new Map();
const loader = new THREE.TextureLoader();

/** @param {Record<string, {color: string, normal: string, rough: string}>} textureUrls */
export function setPhotoTextureUrls(textureUrls) {
    urls = textureUrls ?? {};
}

export function hasPhotoTexture(name) {
    return Boolean(SETS[name] && urls[name]);
}

/**
 * Applies the photographed set to a material, once loaded. The material's UVs
 * are in units of `material.userData.textureSize` metres (applyMetricUVs), so
 * each map is repeated to show the photo at its real size. Its own colour is
 * moved towards white by the set's `tint` (the photo carries the colour), and
 * the drawn bump map is dropped.
 *
 * @returns {Promise<void>}
 */
export async function applyPhotoTexture(material, name) {
    const set = SETS[name];
    const files = urls[name];
    if (!set || !files) {
        return;
    }
    const maps = await load(name, files);
    const repeat = (material.userData.textureSize ?? set.size) / set.size;
    const scaled = (texture) => {
        const copy = texture.clone();
        copy.repeat.set(repeat, repeat);
        copy.needsUpdate = true;

        return copy;
    };
    material.userData.drawnMaps ??= { map: material.map, bumpMap: material.bumpMap, bumpScale: material.bumpScale, color: material.color.clone(), roughness: material.roughness };
    material.map = scaled(maps.color);
    material.normalMap = scaled(maps.normal);
    material.normalScale.setScalar(set.normalScale);
    material.roughnessMap = scaled(maps.rough);
    material.roughness = 1;
    material.bumpMap = null;
    material.color.copy(material.userData.drawnMaps.color).lerp(new THREE.Color(0xffffff), set.tint);
    material.needsUpdate = true;
}

/** Back to the drawn texture (lower tiers). */
export function removePhotoTexture(material) {
    const drawn = material.userData.drawnMaps;
    if (!drawn) {
        return;
    }
    [material.map, material.normalMap, material.roughnessMap].forEach((texture) => texture?.dispose());
    material.map = drawn.map;
    material.bumpMap = drawn.bumpMap;
    material.bumpScale = drawn.bumpScale;
    material.normalMap = null;
    material.roughnessMap = null;
    material.roughness = drawn.roughness;
    material.color.copy(drawn.color);
    delete material.userData.drawnMaps;
    material.needsUpdate = true;
}

function load(name, files) {
    if (!loads.has(name)) {
        const texture = (url, colour) => loader.loadAsync(url).then((map) => {
            map.wrapS = map.wrapT = THREE.RepeatWrapping;
            map.colorSpace = colour ? THREE.SRGBColorSpace : THREE.NoColorSpace;
            map.anisotropy = 8;

            return map;
        });
        loads.set(name, Promise.all([texture(files.color, true), texture(files.normal, false), texture(files.rough, false)])
            .then(([color, normal, rough]) => ({ color, normal, rough })));
    }

    return loads.get(name);
}
