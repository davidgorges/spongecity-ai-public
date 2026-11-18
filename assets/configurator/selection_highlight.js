import * as THREE from 'three';
import { LineSegments2 } from 'three/examples/jsm/lines/LineSegments2.js';
import { LineSegmentsGeometry } from 'three/examples/jsm/lines/LineSegmentsGeometry.js';
import { LineMaterial } from 'three/examples/jsm/lines/LineMaterial.js';
import { COLOUR_GLOW, COLOUR_LINE, densify } from './plot_highlight.js';
import { triangulate } from './outline_geometry.js';

/** CSS pixels: the outline, and its x-ray where something stands in front of it */
const LINE_WIDTH = 3;
const XRAY_WIDTH = 2.5;
const XRAY_DASH = 0.8;
const XRAY_GAP = 0.5;
const XRAY_OPACITY = 0.9;
const FILL_OPACITY = 0.2;
/** the tint where a crown or a wall hides the ground: faint, but the place still shows */
const FILL_XRAY_OPACITY = 0.12;
/** above the ground layers (paving, a rain garden's planting), so nothing flickers through */
const LIFT = 0.1;
/** a circle (a tree's crown) is drawn with this many segments */
const CIRCLE_SEGMENTS = 48;
/** a crown's ring hangs between its base and its top, at this share */
const CROWN_RING_SHARE = 0.55;
/** in front of the plot's highlight (PlotHighlight: 6 to 11) */
const RENDER_ORDER = 12;

/**
 * The object a person points at in the 3D view (the context menu's, or the one whose panel is open),
 * marked in the app's primary colour like the plot: on the ground a tint and an outline that follow
 * the terrain; a building framed from its foot to its roof; a tree's crown as a ring at its height
 * over its drip line on the ground; a sail at its height. A dashed x-ray shows what hides it.
 */
export class SelectionHighlight {
    group = new THREE.Group();
    #lineMaterial = new LineMaterial({ color: COLOUR_LINE, linewidth: LINE_WIDTH, transparent: true, depthWrite: false, toneMapped: false });
    #xrayMaterial = new LineMaterial({
        color: COLOUR_GLOW, linewidth: XRAY_WIDTH, transparent: true, opacity: XRAY_OPACITY, depthWrite: false, depthTest: false, toneMapped: false,
        dashed: true, dashSize: XRAY_DASH, gapSize: XRAY_GAP,
    });
    #fillMaterial = new THREE.MeshBasicMaterial({
        color: COLOUR_GLOW, transparent: true, opacity: FILL_OPACITY, depthWrite: false, toneMapped: false, side: THREE.DoubleSide,
        polygonOffset: true, polygonOffsetFactor: -4, polygonOffsetUnits: -4,
    });
    #fillXrayMaterial = new THREE.MeshBasicMaterial({
        color: COLOUR_GLOW, transparent: true, opacity: FILL_XRAY_OPACITY, depthWrite: false, depthTest: false, toneMapped: false, side: THREE.DoubleSide,
    });
    /** whether what hides it is seen through (dashed); the hover mark is not */
    #xray = true;

    /**
     * @param {{name?: string, line?: number, glow?: number, width?: number, fill?: number, xray?: boolean}} options
     *        another colour for another mark (an alternative the agent had: amber), the group's name, and
     *        for a fainter one (what the pointer is over) a thinner line, a lighter tint and no x-ray
     */
    constructor({ name = 'selection-highlight', line = COLOUR_LINE, glow = COLOUR_GLOW, width = LINE_WIDTH, fill = FILL_OPACITY, xray = true } = {}) {
        this.group.name = name;
        this.#xray = xray;
        this.#lineMaterial.linewidth = width;
        this.#fillMaterial.opacity = fill;
        this.#lineMaterial.color.set(line);
        this.#xrayMaterial.color.set(glow);
        this.#fillMaterial.color.set(glow);
        this.#fillXrayMaterial.color.set(glow);
    }

    /**
     * @param {{points?: number[][], x?: number, z?: number, r?: number, base?: number, top?: number}|null} shape metres (PlanObjects)
     * @param {{heightAt: (x: number, z: number) => number, drape: (g: THREE.BufferGeometry) => THREE.BufferGeometry}} terrain
     */
    show(shape, terrain) {
        this.#clear();
        if (!shape) {
            return;
        }
        const ground = (x, z) => terrain.heightAt(x, z) + LIFT;
        const segments = [];
        if (shape.points?.length >= 3) {
            const path = densify(shape.points);
            if (shape.top === undefined) {
                // on the ground: its outline over the terrain, tinted inside
                this.#ring(segments, path, ground);
                this.#fill(shape.points, terrain);
            } else if (shape.base === shape.top) {
                // hanging at a height (a sail): its outline there
                this.#ring(segments, path, (x, z) => terrain.heightAt(x, z) + shape.top);
            } else {
                // standing on its lowest ground (as SiteBuilder raises a building): foot, roof and corners
                const foot = Math.min(...shape.points.map(([x, z]) => terrain.heightAt(x, z)));
                this.#ring(segments, path, ground);
                this.#ring(segments, path, () => foot + shape.top);
                shape.points.forEach(([x, z]) => segments.push([x, ground(x, z), z], [x, foot + shape.top, z]));
            }
        } else if (shape.r > 0) {
            // a tree: its drip line on the ground, its crown's ring up at its height
            const circle = Array.from({ length: CIRCLE_SEGMENTS }, (_, i) => {
                const angle = (i / CIRCLE_SEGMENTS) * Math.PI * 2;

                return [shape.x + Math.cos(angle) * shape.r, shape.z + Math.sin(angle) * shape.r];
            });
            const foot = terrain.heightAt(shape.x, shape.z);
            const crown = foot + shape.base + (shape.top - shape.base) * CROWN_RING_SHARE;
            this.#ring(segments, circle, ground);
            this.#ring(segments, circle, () => crown);
            segments.push([shape.x, ground(shape.x, shape.z), shape.z], [shape.x, crown, shape.z]);
        }
        if (segments.length === 0) {
            return;
        }
        const positions = segments.flat();
        const line = new LineSegments2(new LineSegmentsGeometry().setPositions(positions), this.#lineMaterial);
        const xray = new LineSegments2(new LineSegmentsGeometry().setPositions(positions), this.#xrayMaterial);
        xray.computeLineDistances();
        const lines = this.#xray ? [line, xray] : [line];
        lines.forEach((object, i) => {
            object.renderOrder = RENDER_ORDER + 1 + i;
            object.frustumCulled = false;
        });
        this.group.add(...lines);
    }

    /** the size of the view in CSS pixels: the line widths are given in them */
    setResolution(width, height) {
        this.#lineMaterial.resolution.set(width, height);
        this.#xrayMaterial.resolution.set(width, height);
    }

    dispose() {
        this.#clear();
        [this.#lineMaterial, this.#xrayMaterial, this.#fillMaterial, this.#fillXrayMaterial].forEach((material) => material.dispose());
    }

    /** a closed path as line segments, each corner at the height `at` gives it */
    #ring(segments, path, at) {
        path.forEach(([x, z], i) => {
            const [nx, nz] = path[(i + 1) % path.length];
            segments.push([x, at(x, z), z], [nx, at(nx, nz), nz]);
        });
    }

    #fill(points, terrain) {
        const ring = points.map(([x, z]) => [x, LIFT, z]);
        const positions = triangulate(ring).flat().flatMap((index) => ring[index]);
        const geometry = new THREE.BufferGeometry();
        geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
        const draped = terrain.drape(geometry);
        const fill = new THREE.Mesh(draped, this.#fillMaterial);
        const meshes = this.#xray ? [fill, new THREE.Mesh(draped, this.#fillXrayMaterial)] : [fill];
        meshes.forEach((mesh) => {
            mesh.renderOrder = RENDER_ORDER;
            mesh.frustumCulled = false;
        });
        this.group.add(...meshes);
    }

    #clear() {
        this.group.traverse((object) => object.geometry?.dispose());
        this.group.clear();
    }
}
