import * as THREE from 'three';
import { mergeGeometries } from 'three/examples/jsm/utils/BufferGeometryUtils.js';
import { GRASS_PAVERS, PERMEABLE_PAVERS, PERMEABLE_REPEAT, SETTS, WATER_BOUND, WEATHERING, proceduralTexture } from './procedural_textures.js';
import { acrossFrom, counterClockwise, inwardNormal, triangulate } from './outline_geometry.js';

/** a part the server marks for this module (InterventionBuilder::APPEARANCE_KIND) */
export const PERMEABLE = 'permeable';

/** the kinds (SiteObject::PERMEABLE_KINDS) and how each is built */
const KINDS = {
    pavers: { texture: PERMEABLE_PAVERS, edging: SETTS, edgingWidth: 0.12, roughness: 0.92, weathered: true },
    grass_pavers: { texture: GRASS_PAVERS, edging: SETTS, edgingWidth: 0.12, roughness: 1 },
    // a water-bound path is held by a slim steel edge in a concrete haunch
    gravel: { texture: WATER_BOUND, edging: null, edgingWidth: 0.05, edgingColour: '#8a8780', roughness: 1 },
};
const DEFAULT_KIND = 'pavers';
/** above the plot's slab (InterventionBuilder::PLOT_TOP 4 mm) and the terrain; the edging a little prouder */
const FIELD_LIFT = 0.012;
const EDGING_LIFT = 0.015;
/** an edging never takes more than this share of the width across */
const EDGING_SHARE = 0.3;
/** one repeat of the weathering patches (not a multiple of a paver repeat), and how much they lighten or darken */
const WEATHERING_METRES = 11.3;
const WEATHERING_STRENGTH = 0.14;

/**
 * Permeable surfaces as they are laid: the field in its real material at its real size (pavers
 * of 20 × 10 cm with grit joints, grass pavers, a water-bound gravel path), the courses running
 * along the surface's longest edge (along the façade, where a zone's edge follows it), framed by
 * an edging (a row of granite setts, or a steel edge for gravel). Everything follows the terrain.
 */
export class PermeableSurfaces {
    group = new THREE.Group();

    #key = '';
    #materials = new Map();

    constructor() {
        this.group.name = 'permeable_surfaces';
    }

    /**
     * @param {{profile: number[][], appearance?: {paving?: string}}[]} parts ([x, z] metres)
     * @param {{drape(geometry: THREE.BufferGeometry): THREE.BufferGeometry}} terrain
     */
    setSurfaces(parts, terrain) {
        const surfaces = parts.filter((part) => (part.profile?.length ?? 0) >= 3);
        const key = JSON.stringify(surfaces.map((part) => [part.profile, part.appearance?.paving]));
        if (key === this.#key && terrain === this.#terrain) {
            return;
        }
        this.#key = key;
        this.#terrain = terrain;
        this.#clear();
        const byMaterial = new Map();
        const add = (material, geometry) => {
            if (!byMaterial.has(material)) {
                byMaterial.set(material, []);
            }
            byMaterial.get(material).push(geometry);
        };
        for (const part of surfaces) {
            const kind = KINDS[part.appearance?.paving] ? part.appearance.paving : DEFAULT_KIND;
            const { field, edging } = surfaceGeometry(part.profile, KINDS[kind]);
            add(this.#material(KINDS[kind].texture, KINDS[kind].roughness, KINDS[kind].weathered), terrain.drape(field));
            add(KINDS[kind].edging ? this.#material(KINDS[kind].edging, 0.85) : this.#plain(KINDS[kind].edgingColour), terrain.drape(edging));
        }
        for (const [material, geometries] of byMaterial) {
            const mesh = new THREE.Mesh(mergeGeometries(geometries), material);
            geometries.forEach((geometry) => geometry.dispose());
            mesh.receiveShadow = true;
            this.group.add(mesh);
        }
    }

    #terrain = null;

    dispose() {
        this.#clear();
        this.#materials.forEach((material) => material.dispose());
    }

    #clear() {
        this.group.traverse((object) => object.isMesh && object.geometry.dispose());
        this.group.clear();
    }

    #material(texture, roughness, weathered = false) {
        if (!this.#materials.has(texture)) {
            const { map, bumpMap, bumpScale } = proceduralTexture(texture);
            const material = new THREE.MeshStandardMaterial({
                map,
                bumpMap,
                bumpScale,
                roughness,
                metalness: 0,
                // it lies millimetres above the plot's slab: drawn in front of it at any distance
                polygonOffset: true,
                polygonOffsetFactor: -2,
                polygonOffsetUnits: -2,
            });
            if (weathered) {
                weather(material, PERMEABLE_REPEAT[texture]);
            }
            this.#materials.set(texture, material);
        }

        return this.#materials.get(texture);
    }

    #plain(colour) {
        if (!this.#materials.has(colour)) {
            this.#materials.set(colour, new THREE.MeshStandardMaterial({ color: colour, roughness: 0.7, metalness: 0.1, polygonOffset: true, polygonOffsetFactor: -3, polygonOffsetUnits: -3 }));
        }

        return this.#materials.get(colour);
    }
}

/**
 * Soft lighter and darker patches over the field, metres across and at a scale the pattern
 * does not share: a large paved field no longer shows the repeat of its texture.
 */
function weather(material, repeat) {
    // its patches lie on the linear bump sheet
    const { bumpMap: patches } = proceduralTexture(WEATHERING);
    material.onBeforeCompile = (shader) => {
        shader.uniforms.weatheringMap = { value: patches };
        shader.uniforms.weatheringScale = { value: repeat / WEATHERING_METRES };
        shader.fragmentShader = shader.fragmentShader
            .replace('#include <common>', '#include <common>\nuniform sampler2D weatheringMap;\nuniform float weatheringScale;')
            .replace('#include <map_fragment>', `#include <map_fragment>
                diffuseColor.rgb *= mix(1.0 - ${WEATHERING_STRENGTH.toFixed(2)}, 1.0 + ${WEATHERING_STRENGTH.toFixed(2)}, texture2D(weatheringMap, vMapUv * weatheringScale).r);`);
    };
    material.customProgramCacheKey = () => 'permeable-weathered';
}

/**
 * The field inside the edging, its UVs turned to the longest edge so the courses run along it,
 * and the edging between the outline and the field, its UVs running along the outline (a row of
 * setts follows every bend).
 */
function surfaceGeometry(profile, { texture, edging: edgingTexture, edgingWidth }) {
    const outline = counterClockwise(profile);
    const count = outline.length;
    const normals = outline.map((_, i) => inwardNormal(outline, i));
    const inset = outline.map((_, i) => Math.min(edgingWidth, EDGING_SHARE * acrossFrom(outline, i, normals[i], edgingWidth * 4)));
    const inner = outline.map(([x, z], i) => [x + normals[i][0] * inset[i], z + normals[i][1] * inset[i]]);

    // the courses run along the longest edge
    let angle = 0;
    let longest = 0;
    outline.forEach(([x1, z1], i) => {
        const [x2, z2] = outline[(i + 1) % count];
        const length = Math.hypot(x2 - x1, z2 - z1);
        if (length > longest) {
            [longest, angle] = [length, Math.atan2(z2 - z1, x2 - x1)];
        }
    });
    const [cos, sin] = [Math.cos(-angle), Math.sin(-angle)];
    const repeat = PERMEABLE_REPEAT[texture];
    const fieldPositions = [];
    const fieldUvs = [];
    const ring = inner.map(([x, z]) => [x, FIELD_LIFT, z]);
    for (const triangle of triangulate(ring)) {
        for (const index of triangle) {
            const [x, y, z] = ring[index];
            fieldPositions.push(x, y, z);
            fieldUvs.push((x * cos - z * sin) / repeat, (x * sin + z * cos) / repeat);
        }
    }

    // the edging: u along the outline (its own length), v across the band
    const edgingRepeat = edgingTexture ? PERMEABLE_REPEAT[edgingTexture] : 1;
    const edgingPositions = [];
    const edgingUvs = [];
    let along = 0;
    for (let i = 0; i < count; i++) {
        const j = (i + 1) % count;
        const length = Math.hypot(outline[j][0] - outline[i][0], outline[j][1] - outline[i][1]);
        const [dx, dz] = [(outline[j][0] - outline[i][0]) / length, (outline[j][1] - outline[i][1]) / length];
        // every corner at its own place along the edge: the inner ones sit shifted by the bend,
        // with the outer ones' u the two triangles of the band would shear the setts in opposite ways
        const at = ([x, z]) => (along + (x - outline[i][0]) * dx + (z - outline[i][1]) * dz) / edgingRepeat;
        along += length;
        const [v0, v1] = [0, edgingWidth / edgingRepeat];
        const quad = [
            [outline[i], at(outline[i]), v0], [inner[i], at(inner[i]), v1], [outline[j], at(outline[j]), v0],
            [outline[j], at(outline[j]), v0], [inner[i], at(inner[i]), v1], [inner[j], at(inner[j]), v1],
        ];
        for (const [[x, z], u, v] of quad) {
            edgingPositions.push(x, EDGING_LIFT, z);
            edgingUvs.push(u, v);
        }
    }

    return { field: flatGeometry(fieldPositions, fieldUvs), edging: flatGeometry(edgingPositions, edgingUvs) };
}

function flatGeometry(positions, uvs) {
    // every triangle faces up (counter-clockwise seen from above), whichever way it was built
    for (let t = 0; t + 8 < positions.length; t += 9) {
        const [ax, az, bx, bz, cx, cz] = [positions[t], positions[t + 2], positions[t + 3], positions[t + 5], positions[t + 6], positions[t + 8]];
        if ((bz - az) * (cx - ax) - (bx - ax) * (cz - az) < 0) {
            for (let k = 0; k < 3; k++) {
                [positions[t + 3 + k], positions[t + 6 + k]] = [positions[t + 6 + k], positions[t + 3 + k]];
            }
            const u = (t / 9) * 6;
            for (let k = 0; k < 2; k++) {
                [uvs[u + 2 + k], uvs[u + 4 + k]] = [uvs[u + 4 + k], uvs[u + 2 + k]];
            }
        }
    }
    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
    geometry.setAttribute('uv', new THREE.Float32BufferAttribute(uvs, 2));
    // a ground layer looks up, whatever the winding
    const normals = new Float32Array(positions.length);
    for (let i = 1; i < normals.length; i += 3) {
        normals[i] = 1;
    }
    geometry.setAttribute('normal', new THREE.BufferAttribute(normals, 3));

    return geometry;
}
