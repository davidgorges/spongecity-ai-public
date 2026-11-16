import * as THREE from 'three';
import { mergeGeometries } from 'three/examples/jsm/utils/BufferGeometryUtils.js';

/** sides of a wire: enough to catch a highlight, cheap enough for thousands */
const SIDES = 6;

/**
 * Geometry of a welded wire mat (scene shape "wire_mesh", see
 * docs/configurator/scene-format.md), centred like a box: vertical wires every
 * `pitch` across the width, horizontal wires every `rowSpacing` down from the
 * top row (which sits `overhang` below the top edge), one on each side of the
 * verticals with `doubleBars`, else one behind them. Open tubes without caps:
 * 12 triangles a wire, about 800 for a 2.5 m mat.
 *
 * @param {{size: number[], pitch: number, rowSpacing: number, wires: number[], doubleBars: boolean, overhang: number}} part metres
 * @returns {THREE.BufferGeometry} non-indexed, with uv
 */
export function wireMeshGeometry({ size: [width, height], pitch, rowSpacing, wires: [vertical, horizontal], doubleBars, overhang }) {
    const verticalRadius = vertical / 2;
    const horizontalRadius = horizontal / 2;
    const tubes = [];

    const columns = Math.max(1, Math.floor(width / pitch));
    const firstX = -((columns - 1) * pitch) / 2;
    const verticalTube = new THREE.CylinderGeometry(verticalRadius, verticalRadius, height, SIDES, 1, true);
    for (let i = 0; i < columns; i++) {
        tubes.push(verticalTube.clone().translate(firstX + i * pitch, 0, 0));
    }

    const horizontalTube = new THREE.CylinderGeometry(horizontalRadius, horizontalRadius, width, SIDES, 1, true).rotateZ(Math.PI / 2);
    const offset = verticalRadius + horizontalRadius;
    const depths = doubleBars ? [-offset, offset] : [-offset];
    for (let y = height / 2 - overhang - horizontalRadius; y >= -height / 2 + horizontalRadius - 1e-6; y -= rowSpacing) {
        for (const z of depths) {
            tubes.push(horizontalTube.clone().translate(0, y, z));
        }
    }

    const merged = mergeGeometries(tubes.map((tube) => tube.toNonIndexed()), false);
    tubes.forEach((tube) => tube.dispose());
    verticalTube.dispose();
    horizontalTube.dispose();

    return merged;
}
