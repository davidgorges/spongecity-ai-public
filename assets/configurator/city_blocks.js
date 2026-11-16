import * as THREE from 'three';
import { FACADE_BRICK, FACADE_PLASTER, FACADE_RIBBON, proceduralTexture } from './procedural_textures.js';

/**
 * The city around an aerial site: perimeter blocks (the usual European street grid,
 * buildings along the edges of each block, a courtyard inside) beyond the imported
 * site, out into the haze. Generic, not the real neighbours: they only give the
 * site its context. Instanced boxes, one mesh per façade kind plus one for the roofs:
 * a few draw calls for a few thousand buildings. Seeded: the same city on every load.
 *
 * Window façades use the site's façade textures; the UVs come from world positions
 * in the vertex shader (u along the wall, v up, in metres), so every box, whatever
 * its size, shows windows on its storeys.
 *
 * A suburban site (scene hint `suburban`) gets a suburb instead: detached two-storey
 * houses with gable roofs along the streets. Generic buildings between the camera
 * and the site sink out of the way (fadeNearCamera), so they never hide the site.
 */
export class CityBlocks {
    /** the street grid: one block plus its street, metres */
    static CELL = 64;
    static STREET = 14;
    static BAR_DEPTH = 12;
    /** façade sheets repeat every 12 m (Site\Facade) */
    static TEXTURE_SIZE = 12;
    static ROOF_THICKNESS = 0.6;
    static PARK_SHARE = 0.12;
    /** blocks per quality detail level (0–3): the ring's outer radius */
    static OUTER = [260, 420, 560, 700];
    static FACADES = [
        { texture: FACADE_PLASTER, colours: [0xefe6d6, 0xf2efe8, 0xe6d3b3, 0xd9d4cc, 0xe9d9c4, 0xeee2b8], share: 0.5 },
        { texture: FACADE_BRICK, colours: [0xa4553c, 0x8e4632, 0xb46a4c, 0x7b4334, 0xc9a27a], share: 0.35 },
        { texture: FACADE_RIBBON, colours: [0xd8d8d4, 0xc9ccce, 0xe6e3dc, 0xb9c2c8], share: 0.15 },
    ];
    static ROOF_COLOURS = [0x8d8a84, 0x77756f, 0x9a968d, 0x6c6b68, 0x8a4636];
    /** suburb: houses along the streets of each block, with gardens between */
    static HOUSE_WIDTH = 10;
    static HOUSE_DEPTH = 9;
    static HOUSE_GAP = 7;
    static HOUSE_SETBACK = 5;
    static HOUSE_EAVES = 6.4;
    static HOUSE_RIDGE = 4;
    static HOUSE_FACADES = [
        { texture: FACADE_PLASTER, colours: [0xf2efe8, 0xefe6d6, 0xe6d3b3, 0xe9d9c4], share: 0.6 },
        { texture: FACADE_BRICK, colours: [0xa4553c, 0x8e4632, 0xb46a4c, 0xc9a27a], share: 0.4 },
    ];
    static TILE_COLOURS = [0xa4533b, 0x8a4636, 0x4c4b4d, 0x6b4a3c, 0x5a5a5c];

    #group = new THREE.Group();
    #materials = [];

    /** generic buildings keep this far from the site's own objects, metres */
    static CLEARANCE = 6;

    /**
     * @param {number} inner radius around the centre the imported site covers: no blocks inside
     * @param {number} detail quality level 0–3
     * @param {{minX: number, maxX: number, minZ: number, maxZ: number}[]} clear the site's objects (buildings, roads): no block overlaps them
     */
    /** @param {(x: number, z: number) => number} heightAt the ground (the site's terrain; flat by default) */
    constructor(inner, detail, clear = [], suburban = false, heightAt = () => 0) {
        this.#group.name = 'city';
        const outer = CityBlocks.OUTER[detail] ?? CityBlocks.OUTER[1];
        const margin = CityBlocks.CLEARANCE;
        const free = (bar) => Math.hypot((bar.minX + bar.maxX) / 2, (bar.minZ + bar.maxZ) / 2) > inner
            && !clear.some((area) => bar.maxX > area.minX - margin && bar.minX < area.maxX + margin && bar.maxZ > area.minZ - margin && bar.minZ < area.maxZ + margin);
        const random = seededRandom(41);
        const bars = (suburban ? this.#suburb(outer, random) : this.#layout(outer, random)).filter(free)
            // each block stands on the lowest ground under its corners
            .map((bar) => ({ ...bar, ground: Math.min(heightAt(bar.minX, bar.minZ), heightAt(bar.maxX, bar.minZ), heightAt(bar.minX, bar.maxZ), heightAt(bar.maxX, bar.maxZ)) }));
        this.#group.add(...this.#meshes(bars, suburban ? CityBlocks.HOUSE_FACADES : CityBlocks.FACADES));
        /** where streets run (centre lines along x and z), for street trees */
        this.streets = { cell: CityBlocks.CELL, street: CityBlocks.STREET, inner, outer };
    }

    get object() {
        return this.#group;
    }

    /** Lit windows in the evening: 0 by day, 1 at night. */
    setLightLevel(level) {
        for (const material of this.#materials) {
            material.emissiveIntensity = level * 1.2;
        }
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

    /** Perimeter bars along the edges of every block of the street grid (some blocks are parks). */
    #layout(outer, random) {
        const { CELL, STREET, BAR_DEPTH } = CityBlocks;
        const bars = [];
        const reach = Math.ceil(outer / CELL);
        for (let i = -reach; i <= reach; i++) {
            for (let j = -reach; j <= reach; j++) {
                const [x0, z0] = [i * CELL + STREET / 2, j * CELL + STREET / 2];
                const [x1, z1] = [x0 + CELL - STREET, z0 + CELL - STREET];
                const centre = Math.hypot((x0 + x1) / 2, (z0 + z1) / 2);
                if (centre > outer || random() < CityBlocks.PARK_SHARE) {
                    continue;
                }
                // four to seven storeys, an occasional tall one
                const storeys = 4 + Math.floor(random() * 4) + (random() < 0.06 ? 6 : 0);
                const sides = [
                    [x0, z0, x1, z0 + BAR_DEPTH],
                    [x0, z1 - BAR_DEPTH, x1, z1],
                    [x0, z0 + BAR_DEPTH, x0 + BAR_DEPTH, z1 - BAR_DEPTH],
                    [x1 - BAR_DEPTH, z0 + BAR_DEPTH, x1, z1 - BAR_DEPTH],
                ];
                for (const [ax, az, bx, bz] of sides) {
                    // each side in two to four buildings of their own height and colour, with the odd gap
                    const alongX = bx - ax > bz - az;
                    const length = alongX ? bx - ax : bz - az;
                    const parts = 2 + Math.floor(random() * 3);
                    for (let p = 0; p < parts; p++) {
                        if (random() < 0.08) {
                            continue;
                        }
                        const from = (p / parts) * length;
                        const to = ((p + 1) / parts) * length;
                        const height = (storeys + Math.round((random() - 0.5) * 2)) * 3 + 0.4;
                        bars.push({
                            minX: alongX ? ax + from : ax, maxX: alongX ? ax + to : bx,
                            minZ: alongX ? az : az + from, maxZ: alongX ? bz : az + to,
                            height,
                            facade: pickWeighted(CityBlocks.FACADES, random()),
                            colour: random(),
                            roof: random(),
                        });
                    }
                }
            }
        }

        return bars;
    }

    /** Detached houses along both sides of every street, set back behind front gardens. */
    #suburb(outer, random) {
        const { CELL, STREET, HOUSE_WIDTH, HOUSE_DEPTH, HOUSE_GAP, HOUSE_SETBACK } = CityBlocks;
        const houses = [];
        const reach = Math.ceil(outer / CELL);
        const pitch = HOUSE_WIDTH + HOUSE_GAP;
        for (let i = -reach; i <= reach; i++) {
            for (let j = -reach; j <= reach; j++) {
                const [x0, z0] = [i * CELL + STREET / 2, j * CELL + STREET / 2];
                const side = CELL - STREET;
                if (Math.hypot(x0 + side / 2, z0 + side / 2) > outer || random() < CityBlocks.PARK_SHARE) {
                    continue;
                }
                // a row facing the street on the block's north and south edges
                for (const [rowZ, facesNorth] of [[z0 + HOUSE_SETBACK, true], [z0 + side - HOUSE_SETBACK - HOUSE_DEPTH, false]]) {
                    for (let x = x0 + 2; x + HOUSE_WIDTH <= x0 + side - 2; x += pitch) {
                        if (random() < 0.1) {
                            continue;
                        }
                        const width = HOUSE_WIDTH * (0.8 + random() * 0.35);
                        houses.push({
                            minX: x, maxX: x + width, minZ: rowZ, maxZ: rowZ + HOUSE_DEPTH * (0.85 + random() * 0.25),
                            height: CityBlocks.HOUSE_EAVES * (random() < 0.25 ? 0.55 : 1),
                            facade: pickWeighted(CityBlocks.HOUSE_FACADES, random()),
                            colour: random(),
                            roof: random(),
                            pitched: true,
                            facesNorth,
                        });
                    }
                }
            }
        }

        return houses;
    }

    #meshes(bars, facades = CityBlocks.FACADES) {
        const box = new THREE.BoxGeometry(1, 1, 1).translate(0, 0.5, 0);
        const matrix = new THREE.Matrix4();
        const colour = new THREE.Color();
        const meshes = facades.map((facade) => {
            const own = bars.filter((bar) => bar.facade === facade);
            const mesh = new THREE.InstancedMesh(box, this.#facadeMaterial(facade.texture), Math.max(1, own.length));
            mesh.count = own.length;
            own.forEach((bar, i) => {
                matrix.makeScale(bar.maxX - bar.minX, bar.height, bar.maxZ - bar.minZ)
                    .setPosition((bar.minX + bar.maxX) / 2, bar.ground, (bar.minZ + bar.maxZ) / 2);
                mesh.setMatrixAt(i, matrix);
                mesh.setColorAt(i, colour.setHex(facade.colours[Math.floor(bar.colour * facade.colours.length)]));
            });
            mesh.name = `city_${facade.texture}`;

            return mesh;
        });

        const flat = bars.filter((bar) => !bar.pitched);
        const roofs = new THREE.InstancedMesh(box, fadeNearCamera(new THREE.MeshStandardMaterial({ color: 0xffffff, roughness: 0.95 })), Math.max(1, flat.length));
        roofs.count = flat.length;
        flat.forEach((bar, i) => {
            matrix.makeScale(bar.maxX - bar.minX + 0.3, CityBlocks.ROOF_THICKNESS, bar.maxZ - bar.minZ + 0.3)
                .setPosition((bar.minX + bar.maxX) / 2, bar.ground + bar.height, (bar.minZ + bar.maxZ) / 2);
            roofs.setMatrixAt(i, matrix);
            roofs.setColorAt(i, colour.setHex(CityBlocks.ROOF_COLOURS[Math.floor(bar.roof * CityBlocks.ROOF_COLOURS.length)]));
        });
        roofs.name = 'city_roofs';

        // gable roofs: a unit prism (ridge along x), scaled over each house with a little overhang
        const pitched = bars.filter((bar) => bar.pitched);
        const gables = new THREE.InstancedMesh(gablePrism(), fadeNearCamera(new THREE.MeshStandardMaterial({ color: 0xffffff, roughness: 0.9, flatShading: true })), Math.max(1, pitched.length));
        gables.count = pitched.length;
        const turn = new THREE.Matrix4();
        pitched.forEach((bar, i) => {
            const [w, d] = [bar.maxX - bar.minX, bar.maxZ - bar.minZ];
            const alongX = w >= d;
            matrix.makeScale((alongX ? w : d) + 0.8, CityBlocks.HOUSE_RIDGE * (bar.height < CityBlocks.HOUSE_EAVES ? 0.6 : 1), (alongX ? d : w) + 0.8);
            if (!alongX) {
                matrix.premultiply(turn.makeRotationY(Math.PI / 2));
            }
            matrix.setPosition((bar.minX + bar.maxX) / 2, bar.ground + bar.height, (bar.minZ + bar.maxZ) / 2);
            gables.setMatrixAt(i, matrix);
            gables.setColorAt(i, colour.setHex(CityBlocks.TILE_COLOURS[Math.floor(bar.roof * CityBlocks.TILE_COLOURS.length)]));
        });
        gables.name = 'city_gables';

        for (const mesh of [...meshes, roofs, gables]) {
            mesh.castShadow = false;
            mesh.receiveShadow = false;
            skipOverridePasses(mesh);
        }

        return [...meshes, roofs, gables];
    }

    /** a façade material whose UVs follow the walls in world metres (see the class comment) */
    #facadeMaterial(name) {
        const texture = proceduralTexture(name);
        const material = new THREE.MeshStandardMaterial({
            color: 0xffffff,
            roughness: 0.85,
            map: texture.map,
            emissive: 0xffd29a,
            emissiveMap: texture.glowMap,
            emissiveIntensity: 0,
        });
        material.onBeforeCompile = (shader) => {
            shader.uniforms.cityTextureSize = { value: CityBlocks.TEXTURE_SIZE };
            shader.vertexShader = `uniform float cityTextureSize;\n${shader.vertexShader}`.replace(
                '#include <uv_vertex>',
                `#include <uv_vertex>
                vec4 cityWorld = modelMatrix * instanceMatrix * vec4(position, 1.0);
                vec2 cityUv = (abs(normal.x) > 0.5 ? cityWorld.zy : (abs(normal.z) > 0.5 ? cityWorld.xy : vec2(0.02, 0.98) * cityTextureSize)) / cityTextureSize;
                vMapUv = (mapTransform * vec3(cityUv, 1.0)).xy;
                vEmissiveMapUv = (emissiveMapTransform * vec3(cityUv, 1.0)).xy;`,
            );
        };
        material.customProgramCacheKey = () => 'city-facade';
        fadeNearCamera(material);
        this.#materials.push(material);

        return material;
    }
}

/**
 * Generic buildings and trees within CLEAR_SIGHT_NEAR of the line from the camera to the
 * site (the site lies around the origin) are gone, by CLEAR_SIGHT_FAR they stand whole
 * again, metres: they sink into the ground between, so a low view across the city
 * still shows the site.
 */
export const CLEAR_SIGHT_NEAR = 30;
export const CLEAR_SIGHT_FAR = 60;
/** how far below the ground a building out of the way goes (metres): deeper than the ground's thickness */
const CLEAR_SIGHT_DEPTH = 2;

/**
 * Lets an instanced piece of generic scenery step out of the view of the site: each
 * instance whose centre stands near the line of sight from the camera to the origin
 * (and in front of the origin) is lowered towards the ground, whole. Chains a
 * material's own onBeforeCompile.
 */
export function fadeNearCamera(material) {
    const previous = material.onBeforeCompile;
    material.onBeforeCompile = (shader, renderer) => {
        previous?.call(material, shader, renderer);
        shader.uniforms.clearSightNear = { value: CLEAR_SIGHT_NEAR };
        shader.uniforms.clearSightFar = { value: CLEAR_SIGHT_FAR };
        const header = `#define CLEAR_SIGHT_DEPTH ${CLEAR_SIGHT_DEPTH.toFixed(1)}\nuniform float clearSightNear;\nuniform float clearSightFar;\n#ifdef USE_SIGHT_CENTRE\nattribute vec3 sightCentre;\n#endif\n`;
        shader.vertexShader = `${header}${shader.vertexShader}`.replace(
            '#include <project_vertex>',
            `#if defined(USE_INSTANCING) || defined(USE_SIGHT_CENTRE)
                #ifdef USE_INSTANCING
                    vec4 sightWorld = modelMatrix * instanceMatrix * vec4(transformed, 1.0);
                    vec3 sightMiddle = (modelMatrix * instanceMatrix * vec4(0.0, 0.0, 0.0, 1.0)).xyz;
                #else
                    // merged meshes: every vertex carries the middle of its building
                    vec4 sightWorld = modelMatrix * vec4(transformed, 1.0);
                    vec3 sightMiddle = (modelMatrix * vec4(sightCentre, 1.0)).xyz;
                #endif
                vec2 sightEye = cameraPosition.xz;
                vec2 sightToSite = -sightEye;
                float sightLength = max(length(sightToSite), 1.0);
                vec2 sightDirection = sightToSite / sightLength;
                vec2 sightOffset = sightMiddle.xz - sightEye;
                float sightAlong = dot(sightOffset, sightDirection);
                float sightAside = length(sightOffset - sightDirection * sightAlong);
                // only between the camera and the site matters; beyond the site nothing hides it
                float sightKeep = sightAlong < 0.0 || sightAlong > sightLength ? 1.0 : smoothstep(clearSightNear, clearSightFar, sightAside);
                // sink, not squash: a building on the way down disappears into the ground
                sightWorld.y = mix(-CLEAR_SIGHT_DEPTH, sightWorld.y, sightKeep);
                vec4 mvPosition = viewMatrix * sightWorld;
                gl_Position = projectionMatrix * mvPosition;
            #else
                #include <project_vertex>
            #endif`,
        );
    };
    const key = material.customProgramCacheKey?.() ?? '';
    material.customProgramCacheKey = () => `${key}|clear-sight`;

    return material;
}

/**
 * Keeps an instanced mesh out of passes that draw the scene with one override material
 * (ambient occlusion's normal pass): that material knows nothing of fadeNearCamera,
 * so buildings out of the way would come back there as ghosts. Generic scenery needs
 * no ambient occlusion.
 */
export function skipOverridePasses(mesh) {
    // instanced: no instances; merged: an empty draw range, for the length of that pass
    let saved = null;
    mesh.onBeforeRender = (renderer, scene) => {
        if (!scene.overrideMaterial) {
            return;
        }
        if (mesh.isInstancedMesh) {
            saved = mesh.count;
            mesh.count = 0;
        } else {
            saved = { ...mesh.geometry.drawRange };
            mesh.geometry.setDrawRange(0, 0);
        }
    };
    mesh.onAfterRender = () => {
        if (saved === null) {
            return;
        }
        if (mesh.isInstancedMesh) {
            mesh.count = saved;
        } else {
            mesh.geometry.setDrawRange(saved.start, saved.count);
        }
        saved = null;
    };

    return mesh;
}

/** a gable roof of 1 × 1 footprint and 1 high, ridge along x, standing on y = 0 */
function gablePrism() {
    const geometry = new THREE.BufferGeometry();
    const h = 0.5;
    const v = [
        // two slopes
        -h, 0, h, h, 0, h, h, 1, 0, -h, 0, h, h, 1, 0, -h, 1, 0,
        h, 0, -h, -h, 0, -h, -h, 1, 0, h, 0, -h, -h, 1, 0, h, 1, 0,
        // two gable ends
        h, 0, h, h, 0, -h, h, 1, 0,
        -h, 0, -h, -h, 0, h, -h, 1, 0,
    ];
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(v, 3));
    geometry.computeVertexNormals();

    return geometry;
}

function pickWeighted(options, value) {
    let sum = 0;
    for (const option of options) {
        sum += option.share;
        if (value < sum) {
            return option;
        }
    }

    return options[options.length - 1];
}

/** mulberry32: the same city on every load */
function seededRandom(seed) {
    return () => {
        seed |= 0;
        seed = (seed + 0x6d2b79f5) | 0;
        let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;

        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}
