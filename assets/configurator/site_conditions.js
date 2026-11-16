import * as THREE from 'three';
import { mergeGeometries } from 'three/examples/jsm/utils/BufferGeometryUtils.js';
import { skipOverridePasses } from './city_blocks.js';
import { TerrainField } from './terrain.js';

/**
 * The infiltration potential of a site's ground (Hamburg's Versickerungspotentialkarte,
 * from the plan's conditions endpoint, App\SpongeCity\Conditions\SiteConditions) drawn
 * as a translucent map on the ground: one merged mesh coloured per class, above every
 * ground layer and intervention, so it reads like a plan overlay. Hidden until asked for.
 */
export class InfiltrationOverlay {
    /**
     * class → colour; best (blue) to worst (red), as in the legend (PlanWorkspace). Saturated on
     * purpose: at the higher tiers the composer's OutputPass tone-maps the whole frame (the
     * material's toneMapped does not apply there), which pales them to the legend's tones.
     */
    static COLOURS = {
        possible: 0x0a4fc4,
        likely: 0x1fa0e0,
        limited: 0xf09a1a,
        unlikely: 0xd8321e,
        unknown: 0x9a9a96,
    };
    /** strong enough to survive the colour grading of the higher tiers (post-processing) */
    static OPACITY = 0.65;
    /** above the highest ground layer (interventions up to 22 mm): see "Depth and flicker" */
    static HEIGHT = 0.03;
    static EDGE_LIFT = 0.002;
    static EDGE_OPACITY = 0.85;

    #group = new THREE.Group();

    /**
     * @param {{infiltration: {points: number[][], potential: string}[]}} conditions plan metres, x east, z south
     * @param {TerrainField} terrain the ground the map lies on
     */
    constructor({ infiltration = [] }, terrain = TerrainField.FLAT) {
        this.#group.name = 'site_conditions';
        this.#group.visible = false;
        const fills = [];
        const edges = [];
        for (const area of infiltration) {
            if (!Array.isArray(area.points) || area.points.length < 3) {
                continue;
            }
            const colour = new THREE.Color(InfiltrationOverlay.COLOURS[area.potential] ?? InfiltrationOverlay.COLOURS.unknown);
            // shapes are drawn in x/y: y = −z, so the rotation below lays them on the ground the right way round
            const toShape = (points) => points.map(([x, z]) => new THREE.Vector2(x, -z));
            const shape = new THREE.Shape(toShape(area.points));
            const holes = (area.holes ?? []).filter((hole) => hole.length >= 3);
            // holes are where the map has another class: they are areas of their own
            shape.holes = holes.map((hole) => new THREE.Path(toShape(hole)));
            const fill = terrain.drape(new THREE.ShapeGeometry(shape).rotateX(-Math.PI / 2).toNonIndexed());
            paint(fill, colour);
            fills.push(fill);
            for (const outline of [area.points, ...holes]) {
                const line = new THREE.BufferGeometry().setFromPoints(edgeSegments(outline, terrain));
                paint(line, colour.clone().multiplyScalar(0.7));
                edges.push(line);
            }
        }
        if (fills.length > 0) {
            const mesh = new THREE.Mesh(mergeGeometries(fills), new THREE.MeshBasicMaterial({
                vertexColors: true,
                transparent: true,
                opacity: InfiltrationOverlay.OPACITY,
                // a map, not a material: the legend's colours, untouched by tone mapping and haze
                toneMapped: false,
                fog: false,
                depthWrite: false,
                side: THREE.DoubleSide,
                polygonOffset: true,
                polygonOffsetFactor: -4,
                polygonOffsetUnits: -16,
            }));
            mesh.name = 'infiltration_areas';
            mesh.position.y = InfiltrationOverlay.HEIGHT;
            mesh.renderOrder = 10;
            this.#group.add(mesh);
            const lines = new THREE.LineSegments(mergeGeometries(edges), new THREE.LineBasicMaterial({
                vertexColors: true, transparent: true, opacity: InfiltrationOverlay.EDGE_OPACITY, depthWrite: false, toneMapped: false, fog: false,
            }));
            lines.name = 'infiltration_edges';
            lines.position.y = InfiltrationOverlay.HEIGHT + InfiltrationOverlay.EDGE_LIFT;
            lines.renderOrder = 11;
            this.#group.add(lines);
        }
        for (const object of this.#group.children) {
            object.castShadow = false;
            object.receiveShadow = false;
            // the ambient-occlusion and other override passes would draw it opaque
            skipOverridePasses(object);
        }
    }

    get object() {
        return this.#group;
    }

    get isEmpty() {
        return this.#group.children.length === 0;
    }

    get visible() {
        return this.#group.visible;
    }

    set visible(visible) {
        this.#group.visible = visible;
    }

    dispose() {
        this.#group.traverse((object) => {
            object.geometry?.dispose();
            object.material?.dispose();
        });
        this.#group.removeFromParent();
    }
}

/** The segments of a closed outline, cut every couple of metres and laid on the ground. */
function edgeSegments(outline, terrain) {
    const points = [];
    const ring = [...outline, outline[0]];
    for (let i = 1; i < ring.length; i++) {
        const [[ax, az], [bx, bz]] = [ring[i - 1], ring[i]];
        const steps = terrain.isFlat ? 1 : Math.max(1, Math.ceil(Math.hypot(bx - ax, bz - az) / TerrainField.DRAPE_EDGE));
        for (let k = 0; k < steps; k++) {
            const [x0, z0] = [ax + ((bx - ax) * k) / steps, az + ((bz - az) * k) / steps];
            const [x1, z1] = [ax + ((bx - ax) * (k + 1)) / steps, az + ((bz - az) * (k + 1)) / steps];
            points.push(new THREE.Vector3(x0, terrain.heightAt(x0, z0), z0), new THREE.Vector3(x1, terrain.heightAt(x1, z1), z1));
        }
    }

    return points;
}

function paint(geometry, colour) {
    const count = geometry.attributes.position.count;
    const colours = new Float32Array(count * 3);
    for (let i = 0; i < count; i++) {
        colour.toArray(colours, i * 3);
    }
    geometry.setAttribute('color', new THREE.BufferAttribute(colours, 3));
    // merged geometries must carry the same attributes
    geometry.deleteAttribute('uv');
    geometry.deleteAttribute('normal');
}
