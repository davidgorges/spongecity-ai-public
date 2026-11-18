import * as THREE from 'three';
import { Line2 } from 'three/examples/jsm/lines/Line2.js';
import { LineGeometry } from 'three/examples/jsm/lines/LineGeometry.js';
import { LineMaterial } from 'three/examples/jsm/lines/LineMaterial.js';

/** the app's primary colour (--primary), and a lighter one for what glows */
const COLOUR_LINE = 0x5340f4;
const COLOUR_GLOW = 0x8a7dff;
/** metres: the curtain along the edge, the glow fading inwards from it, the rings at the corners */
const CURTAIN_HEIGHT = 3;
const GLOW_WIDTH = 4;
const CORNER_RADIUS = 0.9;
/** CSS pixels: the outline, and its x-ray where trees and buildings hide it */
const LINE_WIDTH = { rest: 3.5, editing: 5 };
const XRAY_WIDTH = 2;
const XRAY_DASH = 1.6;
const XRAY_GAP = 1.1;
/** above the plot's slab and the terrain, so nothing flickers through */
const LIFT = 0.08;
/** edges are cut into pieces this long (metres), so the highlight follows the terrain */
const STEP = 2;
/** the most corners a plot has (SiteObject::MAX_POINTS): the size of the glow shader's array */
const MAX_CORNERS = 40;
/** opacities at rest and while the plot is being edited */
const ALPHA = {
    rest: { curtain: 0.35, glow: 0.35, fill: 0.12, xray: 0.4 },
    editing: { curtain: 0.6, glow: 0.55, fill: 0.22, xray: 0.65 },
};
const RENDER_ORDER = 6;

/**
 * The plot (the analysed area) marked in the 3D view: a tint over all of it, a crisp
 * outline (a dashed x-ray of it shows through what hides it: tree crowns, buildings),
 * a glow fading inwards from the edge (from each point's distance to the nearest edge,
 * so it stays even in any corner), a translucent curtain fading upwards and a ring
 * at every corner. All of it follows the terrain and is drawn untoned, so the colour
 * stays the app's primary in every light. Brighter while the plot map is in use.
 */
export class PlotHighlight {
    group = new THREE.Group();
    #editing = false;
    #fillMaterial = glowMaterial();
    #curtainMaterial = surfaceMaterial(COLOUR_GLOW);
    #lineMaterial = new LineMaterial({ color: COLOUR_LINE, linewidth: LINE_WIDTH.rest, transparent: true, depthWrite: false, toneMapped: false });
    #xrayMaterial = new LineMaterial({
        color: COLOUR_GLOW, linewidth: XRAY_WIDTH, transparent: true, depthWrite: false, depthTest: false, toneMapped: false,
        dashed: true, dashSize: XRAY_DASH, gapSize: XRAY_GAP,
    });
    #cornerMaterial = new THREE.MeshBasicMaterial({ color: COLOUR_LINE, transparent: true, opacity: 0.95, depthWrite: false, toneMapped: false, side: THREE.DoubleSide });

    constructor() {
        this.group.name = 'plot-highlight';
        this.#applyState();
    }

    /**
     * @param {number[][]|null} points the plot's corners [x, z], metres
     * @param {{heightAt: (x: number, z: number) => number, drape: (g: THREE.BufferGeometry) => THREE.BufferGeometry}} terrain
     */
    setOutline(points, terrain) {
        this.#clear();
        if (!points || points.length < 3) {
            return;
        }
        const ground = (x, z) => terrain.heightAt(x, z) + LIFT;
        const path = densify(points);
        const corners = this.#fillMaterial.uniforms.corners.value;
        corners.forEach((corner, i) => corner.set(...(points[Math.min(i, points.length - 1)])));
        this.#fillMaterial.uniforms.count.value = Math.min(points.length, MAX_CORNERS);

        const fill = new THREE.Mesh(this.#fill(points, terrain), this.#fillMaterial);
        const curtain = new THREE.Mesh(this.#curtain(path, ground), this.#curtainMaterial);
        const linePositions = [...path, path[0]].flatMap(([x, z]) => [x, ground(x, z) + 0.02, z]);
        const line = new Line2(new LineGeometry().setPositions(linePositions), this.#lineMaterial);
        const xray = new Line2(new LineGeometry().setPositions(linePositions), this.#xrayMaterial);
        xray.computeLineDistances();
        [fill, curtain, line, xray].forEach((object, i) => {
            object.renderOrder = RENDER_ORDER + i;
            object.frustumCulled = false;
        });
        this.group.add(fill, curtain, line, xray);

        const ring = new THREE.RingGeometry(CORNER_RADIUS * 0.5, CORNER_RADIUS, 32).rotateX(-Math.PI / 2);
        for (const [x, z] of points) {
            const corner = new THREE.Mesh(ring, this.#cornerMaterial);
            corner.position.set(x, ground(x, z) + 0.03, z);
            corner.renderOrder = RENDER_ORDER + 5;
            this.group.add(corner);
        }
    }

    /** @returns {boolean} whether anything changed */
    setEditing(editing) {
        if (this.#editing === editing) {
            return false;
        }
        this.#editing = editing;
        this.#applyState();

        return true;
    }

    /** the size of the view in CSS pixels: the outline's width is given in them */
    setResolution(width, height) {
        this.#lineMaterial.resolution.set(width, height);
        this.#xrayMaterial.resolution.set(width, height);
    }

    dispose() {
        this.#clear();
        [this.#fillMaterial, this.#curtainMaterial, this.#lineMaterial, this.#xrayMaterial, this.#cornerMaterial]
            .forEach((material) => material.dispose());
    }

    #applyState() {
        const alpha = ALPHA[this.#editing ? 'editing' : 'rest'];
        // the curtain carries its fade in its vertices (up to ALPHA.editing): scaled down at rest
        this.#curtainMaterial.opacity = alpha.curtain / ALPHA.editing.curtain;
        this.#fillMaterial.uniforms.fill.value = alpha.fill;
        this.#fillMaterial.uniforms.glow.value = alpha.glow;
        this.#xrayMaterial.opacity = alpha.xray;
        this.#lineMaterial.linewidth = LINE_WIDTH[this.#editing ? 'editing' : 'rest'];
    }

    #clear() {
        const geometries = new Set();
        this.group.traverse((object) => object.geometry && geometries.add(object.geometry));
        geometries.forEach((geometry) => geometry.dispose());
        this.group.clear();
    }

    /** The whole plot, tinted: its outline triangulated and laid over the terrain. */
    #fill(points, terrain) {
        const contour = points.map(([x, z]) => new THREE.Vector2(x, z));
        const positions = THREE.ShapeUtils.triangulateShape(contour, [])
            .flat()
            .flatMap((i) => [points[i][0], LIFT, points[i][1]]);

        return terrain.drape(geometry(positions, null));
    }

    /** A wall standing on the edge, opaque at its foot, gone at its top. */
    #curtain(path, ground) {
        const positions = [];
        const colours = [];
        const foot = ALPHA.editing.curtain;
        for (let i = 0; i < path.length; ++i) {
            const [ax, az] = path[i];
            const [bx, bz] = path[(i + 1) % path.length];
            const ya = ground(ax, az);
            const yb = ground(bx, bz);
            positions.push(ax, ya, az, bx, yb, bz, bx, yb + CURTAIN_HEIGHT, bz, ax, ya, az, bx, yb + CURTAIN_HEIGHT, bz, ax, ya + CURTAIN_HEIGHT, az);
            colours.push(...[foot, foot, 0, foot, 0, 0].flatMap((alpha) => [1, 1, 1, alpha]));
        }

        return geometry(positions, colours);
    }
}

/**
 * The tint over the plot and the glow inside its edge: brighter and lighter the nearer a
 * point lies to an edge of the outline (the corners uniform, in plan metres).
 */
function glowMaterial() {
    return new THREE.ShaderMaterial({
        uniforms: {
            colour: { value: new THREE.Color(COLOUR_LINE) },
            glowColour: { value: new THREE.Color(COLOUR_GLOW) },
            corners: { value: Array.from({ length: MAX_CORNERS }, () => new THREE.Vector2()) },
            count: { value: 0 },
            width: { value: GLOW_WIDTH },
            fill: { value: ALPHA.rest.fill },
            glow: { value: ALPHA.rest.glow },
        },
        vertexShader: `
            varying vec2 vPlan;
            void main() {
                vec4 world = modelMatrix * vec4(position, 1.0);
                vPlan = world.xz;
                gl_Position = projectionMatrix * viewMatrix * world;
            }`,
        fragmentShader: `
            uniform vec3 colour;
            uniform vec3 glowColour;
            uniform vec2 corners[${MAX_CORNERS}];
            uniform int count;
            uniform float width;
            uniform float fill;
            uniform float glow;
            varying vec2 vPlan;
            void main() {
                float nearest = 1e9;
                for (int i = 0; i < ${MAX_CORNERS}; ++i) {
                    if (i >= count) {
                        break;
                    }
                    vec2 a = corners[i];
                    vec2 b = i + 1 < count ? corners[i + 1] : corners[0];
                    vec2 edge = b - a;
                    float t = clamp(dot(vPlan - a, edge) / max(dot(edge, edge), 1e-6), 0.0, 1.0);
                    nearest = min(nearest, length(vPlan - a - edge * t));
                }
                float near = 1.0 - smoothstep(0.0, width, nearest);
                near *= near;
                gl_FragColor = vec4(mix(colour, glowColour, near), fill + glow * near);
                #include <colorspace_fragment>
            }`,
        transparent: true,
        depthWrite: false,
        side: THREE.DoubleSide,
        polygonOffset: true,
        polygonOffsetFactor: -2,
        polygonOffsetUnits: -2,
    });
}

function surfaceMaterial(colour) {
    return new THREE.MeshBasicMaterial({
        color: colour, vertexColors: true, transparent: true, depthWrite: false, toneMapped: false, side: THREE.DoubleSide,
        polygonOffset: true, polygonOffsetFactor: -2, polygonOffsetUnits: -2,
    });
}

function geometry(positions, colours) {
    const result = new THREE.BufferGeometry();
    result.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
    if (colours) {
        result.setAttribute('color', new THREE.Float32BufferAttribute(colours, 4));
    }

    return result;
}

/** The closed outline with every edge cut into pieces of at most STEP metres. */
function densify(points) {
    const path = [];
    points.forEach(([ax, az], i) => {
        const [bx, bz] = points[(i + 1) % points.length];
        const pieces = Math.max(1, Math.ceil(Math.hypot(bx - ax, bz - az) / STEP));
        for (let k = 0; k < pieces; ++k) {
            path.push([ax + (bx - ax) * k / pieces, az + (bz - az) * k / pieces]);
        }
    });

    return path;
}
