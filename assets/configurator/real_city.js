import * as THREE from 'three';
import { mergeGeometries } from 'three/examples/jsm/utils/BufferGeometryUtils.js';
import { fadeNearCamera, skipOverridePasses } from './city_blocks.js';
import { TerrainField } from './terrain.js';
import { ASPHALT, FACADE_BRICK, FACADE_CLADDING, FACADE_PLASTER, FACADE_RIBBON, proceduralTexture } from './procedural_textures.js';

/**
 * The real city around an aerial site: the OpenStreetMap buildings and streets out to a
 * few hundred metres (the plan's surroundings endpoint, App\SpongeCity\Geo\Surroundings),
 * with the same façades as the site's own buildings. Context only, so cheap: one merged
 * mesh per façade kind, one for the roofs, one for the streets, instanced street trees
 * along the carriageways. Like the generic city, buildings step out of the line of sight
 * to the site (fadeNearCamera).
 */
export class RealCity {
    /** façade sheets repeat every 12 m (Site\Facade::BAY × BAYS_PER_REPEAT) */
    static TEXTURE_SIZE = 12;
    static ASPHALT_TEXTURE_SIZE = 6;
    static FACADE_TEXTURES = {
        plaster: FACADE_PLASTER,
        brick: FACADE_BRICK,
        ribbon: FACADE_RIBBON,
        cladding: FACADE_CLADDING,
        plain: FACADE_PLASTER,
    };
    /** a flat roof lies on the walls, a pitched one is drawn as its footprint in the roof's colour */
    static ROOF_LIFT = 0.08;
    /** streets lie just above the lawn and below the site's own (its roads, its plot) */
    static ROAD_HEIGHT = 0.002;
    static ASPHALT = 0x5a5b5d;
    static FOOTWAY = 0xb3aea5;
    /** the site's own roads reach this far (Site\SiteObject::REACH): streets wholly within are already there */
    static SITE_REACH = 100;
    static TREE_SPACING = 20;
    static TREE_SETBACK = 2.5;
    static MAX_TREES = 450;
    /** streets follow the terrain's coarse grid out here (HamburgGeodata::COARSE_SPACING_METRES) */
    static DRAPE_EDGE = 4;

    #group = new THREE.Group();
    #facadeMaterials = [];
    #trees = [];
    #terrain;

    /**
     * @param {{radius: number, buildings: object[], roads: object[]}} surroundings
     * @param {TerrainField} terrain the ground buildings stand on and streets follow
     */
    constructor({ buildings = [], roads = [] }, terrain = TerrainField.FLAT) {
        this.#terrain = terrain;
        this.#group.name = 'real_city';
        this.#group.add(...this.#buildingMeshes(buildings), ...this.#roadMesh(roads));
        this.#trees = this.#treeSpots(roads, buildings);
        for (const mesh of this.#group.children) {
            mesh.castShadow = false;
            mesh.receiveShadow = false;
            skipOverridePasses(mesh);
        }
    }

    get object() {
        return this.#group;
    }

    /** where street trees stand: {x, z, scale, turn, tint} */
    get trees() {
        return this.#trees;
    }

    setLightLevel(level) {
        for (const material of this.#facadeMaterials) {
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

    #buildingMeshes(buildings) {
        const walls = new Map();
        const roofs = [];
        const colour = new THREE.Color();
        buildings.forEach((building, index) => {
            const outline = building.points.map(([x, z]) => new THREE.Vector2(x, -z));
            if (outline.length < 3 || !(building.height > 0)) {
                return;
            }
            const middle = new THREE.Vector3(
                building.points.reduce((sum, [x]) => sum + x, 0) / building.points.length,
                0,
                building.points.reduce((sum, [, z]) => sum + z, 0) / building.points.length,
            );
            const shape = new THREE.Shape(outline);
            // profile (x, -z) extruded along +z, turned up: x stays, the extrusion becomes the height, -(-z) = z
            // on the lowest ground under the footprint: on a slope the uphill walls start below ground
            const ground = this.#terrain.lowestUnder(building.points);
            const wall = new THREE.ExtrudeGeometry(shape, { depth: building.height, bevelEnabled: false }).rotateX(-Math.PI / 2).translate(0, ground, 0);
            wallUVs(wall, RealCity.TEXTURE_SIZE, index);
            paint(wall, colour.set(building.color), middle);
            const kind = RealCity.FACADE_TEXTURES[building.facade] ?? FACADE_PLASTER;
            if (!walls.has(kind)) {
                walls.set(kind, []);
            }
            walls.get(kind).push(wall);

            const roof = new THREE.ShapeGeometry(shape).rotateX(-Math.PI / 2).translate(0, ground + building.height + RealCity.ROOF_LIFT, 0).toNonIndexed();
            roof.setAttribute('uv', new THREE.Float32BufferAttribute(new Float32Array(roof.attributes.position.count * 2), 2));
            paint(roof, colour.set(building.roofColor), middle);
            roofs.push(roof);
        });

        const meshes = [];
        for (const [kind, geometries] of walls) {
            const texture = proceduralTexture(kind);
            const material = new THREE.MeshStandardMaterial({
                color: 0xffffff,
                vertexColors: true,
                roughness: 0.85,
                map: texture.map,
                emissive: 0xffd29a,
                emissiveMap: texture.glowMap,
                emissiveIntensity: 0,
            });
            material.defines = { USE_SIGHT_CENTRE: '' };
            this.#facadeMaterials.push(fadeNearCamera(material));
            meshes.push(named(new THREE.Mesh(merge(geometries), material), `real_city_${kind}`));
        }
        if (roofs.length > 0) {
            const material = new THREE.MeshStandardMaterial({ color: 0xffffff, vertexColors: true, roughness: 0.95 });
            material.defines = { USE_SIGHT_CENTRE: '' };
            meshes.push(named(new THREE.Mesh(merge(roofs), fadeNearCamera(material)), 'real_city_roofs'));
        }

        return meshes;
    }

    /**
     * Flat strips along every street, cut where they enter the site's square (the site
     * draws its own streets there). Footways and carriageways are separate meshes, kept
     * apart in depth by polygon offset rather than by millimetres of height: a few
     * millimetres stop telling apart from a few hundred metres away, and the strips
     * would z-fight in stripes as the camera moves.
     */
    #roadMesh(roads) {
        const strips = { carriageway: [], footway: [] };
        for (const road of roads) {
            const kind = road.kind === 'footway' ? 'footway' : 'carriageway';
            for (let i = 1; i < road.points.length; i++) {
                for (const [a, b] of outsideSquare(road.points[i - 1], road.points[i], RealCity.SITE_REACH)) {
                    const strip = legStrip(a, b, road.width);
                    if (strip) {
                        strips[kind].push(this.#terrain.drape(strip, RealCity.DRAPE_EDGE));
                    }
                }
            }
        }
        const texture = proceduralTexture(ASPHALT);
        const meshes = [];
        for (const [kind, geometries] of Object.entries(strips)) {
            if (geometries.length === 0) {
                continue;
            }
            const footway = kind === 'footway';
            const material = new THREE.MeshStandardMaterial({
                color: footway ? RealCity.FOOTWAY : RealCity.ASPHALT,
                roughness: 0.95,
                map: texture.map,
                // drawn over the ground, footways over carriageways
                polygonOffset: true,
                polygonOffsetFactor: footway ? -2 : -1,
                polygonOffsetUnits: footway ? -8 : -4,
            });
            meshes.push(named(new THREE.Mesh(merge(geometries), material), `real_city_${kind}s`));
        }

        return meshes;
    }

    /** trees every TREE_SPACING metres along both sides of the carriageways, not in a building */
    #treeSpots(roads, buildings) {
        const random = seededRandom(31);
        const footprints = buildings.map((building) => building.points);
        const spots = [];
        for (const road of roads) {
            if (road.kind === 'footway' || road.width < 6) {
                continue;
            }
            for (let i = 1; i < road.points.length && spots.length < RealCity.MAX_TREES; i++) {
                const [[ax, az], [bx, bz]] = [road.points[i - 1], road.points[i]];
                const length = Math.hypot(bx - ax, bz - az);
                const [dx, dz] = [(bx - ax) / length, (bz - az) / length];
                const aside = road.width / 2 + RealCity.TREE_SETBACK;
                for (let t = RealCity.TREE_SPACING / 2; t < length; t += RealCity.TREE_SPACING) {
                    for (const side of [-1, 1]) {
                        const x = ax + dx * t - dz * aside * side;
                        const z = az + dz * t + dx * aside * side;
                        const onSite = Math.abs(x) < RealCity.SITE_REACH && Math.abs(z) < RealCity.SITE_REACH;
                        if (onSite || footprints.some((outline) => contains(outline, x, z))) {
                            continue;
                        }
                        spots.push({ x, z, y: this.#terrain.heightAt(x, z), scale: 0.75 + random() * 0.45, turn: random() * Math.PI * 2, tint: random() });
                    }
                }
            }
        }

        return spots;
    }
}

/**
 * Wall UVs in world metres: u along each wall, v up from the ground; the roof cap keeps
 * a plain spot of the sheet. `seed` shifts each building by whole bays and storeys.
 */
function wallUVs(geometry, textureSize, seed) {
    const position = geometry.attributes.position;
    const normal = geometry.attributes.normal;
    const uv = geometry.attributes.uv;
    const bays = 4;
    const shiftU = ((seed * 7) % 97) / bays;
    const shiftV = ((seed * 13) % 89) / bays;
    for (let i = 0; i < position.count; i += 3) {
        if (Math.abs(normal.getY(i)) > 0.5) {
            for (let k = i; k < i + 3; k++) {
                uv.setXY(k, 0.02, 0.98);
            }
            continue;
        }
        const [wx, wz] = [-normal.getZ(i), normal.getX(i)];
        for (let k = i; k < i + 3; k++) {
            const along = position.getX(k) * wx + position.getZ(k) * wz;
            uv.setXY(k, along / textureSize + shiftU, position.getY(k) / textureSize + shiftV);
        }
    }
    uv.needsUpdate = true;
}

/** vertex colours (tint of the texture) and the building's middle on every vertex (fadeNearCamera) */
function paint(geometry, colour, middle) {
    const count = geometry.attributes.position.count;
    const colours = new Float32Array(count * 3);
    const middles = new Float32Array(count * 3);
    for (let i = 0; i < count; i++) {
        colours.set([colour.r, colour.g, colour.b], i * 3);
        middles.set([middle.x, middle.y, middle.z], i * 3);
    }
    geometry.setAttribute('color', new THREE.BufferAttribute(colours, 3));
    geometry.setAttribute('sightCentre', new THREE.BufferAttribute(middles, 3));
}

/**
 * The parts of the leg a→b outside the square |x|, |z| < half (the site's square):
 * none, the whole leg, or one or two pieces where it crosses the square's edge.
 */
function outsideSquare(a, b, half) {
    const [dx, dz] = [b[0] - a[0], b[1] - a[1]];
    let [enter, leave] = [0, 1];
    for (const [p, q] of [[-dx, a[0] + half], [dx, half - a[0]], [-dz, a[1] + half], [dz, half - a[1]]]) {
        if (p === 0) {
            if (q < 0) {
                return [[a, b]]; // parallel to this side and outside it
            }
            continue;
        }
        const t = q / p;
        if (p < 0) {
            enter = Math.max(enter, t);
        } else {
            leave = Math.min(leave, t);
        }
    }
    if (enter >= leave) {
        return [[a, b]]; // misses the square
    }
    const at = (t) => [a[0] + t * dx, a[1] + t * dz];
    const pieces = [];
    if (enter > 0) {
        pieces.push([a, at(enter)]);
    }
    if (leave < 1) {
        pieces.push([at(leave), b]);
    }

    return pieces;
}

/** a flat quad along a street leg, a little longer so legs overlap at the bends; UVs in metres */
function legStrip([ax, az], [bx, bz], width) {
    const length = Math.hypot(bx - ax, bz - az);
    if (length < 0.5) {
        return null;
    }
    const [dx, dz] = [(bx - ax) / length, (bz - az) / length];
    const [nx, nz] = [-dz * width / 2, dx * width / 2];
    const [ex, ez] = [dx * width / 2, dz * width / 2];
    const corners = [
        [ax - ex + nx, az - ez + nz], [ax - ex - nx, az - ez - nz], [bx + ex - nx, bz + ez - nz], [bx + ex + nx, bz + ez + nz],
    ];
    const y = RealCity.ROAD_HEIGHT;
    const order = [0, 1, 2, 0, 2, 3];
    const positions = order.flatMap((i) => [corners[i][0], y, corners[i][1]]);
    const uvs = order.flatMap((i) => [corners[i][0] / RealCity.ASPHALT_TEXTURE_SIZE, corners[i][1] / RealCity.ASPHALT_TEXTURE_SIZE]);
    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
    geometry.setAttribute('uv', new THREE.Float32BufferAttribute(uvs, 2));
    geometry.computeVertexNormals();
    // make sure the quad faces up whichever way the leg runs
    if (geometry.attributes.normal.getY(0) < 0) {
        geometry.setAttribute('position', new THREE.Float32BufferAttribute([0, 2, 1, 3, 5, 4].flatMap((i) => positions.slice(i * 3, i * 3 + 3)), 3));
        geometry.setAttribute('uv', new THREE.Float32BufferAttribute([0, 2, 1, 3, 5, 4].flatMap((i) => uvs.slice(i * 2, i * 2 + 2)), 2));
        geometry.computeVertexNormals();
    }

    return geometry;
}

function merge(geometries) {
    const merged = mergeGeometries(geometries.map((geometry) => (geometry.index ? geometry.toNonIndexed() : geometry)), false);
    geometries.forEach((geometry) => geometry.dispose());

    return merged;
}

function named(mesh, name) {
    mesh.name = name;

    return mesh;
}

function contains(outline, x, z) {
    let inside = false;
    for (let i = 0, j = outline.length - 1; i < outline.length; j = i++) {
        const [xi, zi] = outline[i];
        const [xj, zj] = outline[j];
        if ((zi > z) !== (zj > z) && x < ((xj - xi) * (z - zi)) / (zj - zi) + xi) {
            inside = !inside;
        }
    }

    return inside;
}

/** mulberry32: the same trees on every load */
function seededRandom(seed) {
    return () => {
        seed |= 0;
        seed = (seed + 0x6d2b79f5) | 0;
        let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;

        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}
