import * as THREE from 'three';
import { mergeGeometries } from 'three/examples/jsm/utils/BufferGeometryUtils.js';

/**
 * Real stones for the gabion fill in photo mode. The live view draws the fill
 * as a box with a stone texture, which a path tracer close up shows as flat
 * as a print. Here every fill box gets broken granite (6–9 cm, irregular,
 * a little flattened, each its own shade) packed in two layers behind both
 * faces and on top; the box stays behind them, darkened, as the shadowed gaps.
 */
export class PhotoStones {
    /** centre to centre of neighbouring stones, metres */
    static SPACING = 0.062;
    static SIZE = [0.034, 0.048];
    /** light grey granite, some warmer, some bluer */
    static SHADES = [0x9b978f, 0xb3afa6, 0x8a8781, 0xc4bfb5, 0xa59c90, 0x8f939a];

    /**
     * @param {{size: number[], position: number[], rotation: number[]}[]} fills the fill boxes (scene parts, metres)
     * @returns {THREE.Mesh|null}
     */
    static create(fills) {
        if (fills.length === 0) {
            return null;
        }
        const random = seededRandom(23);
        // a few stone shapes, reused turned and scaled: every stone still looks different
        const shapes = Array.from({ length: 12 }, () => stoneShape(random));
        const pieces = [];
        const matrix = new THREE.Matrix4();
        const local = new THREE.Matrix4();
        const quaternion = new THREE.Quaternion();
        const euler = new THREE.Euler();
        const colour = new THREE.Color();

        for (const fill of fills) {
            const [width, height, depth] = fill.size;
            const part = new THREE.Matrix4().compose(
                new THREE.Vector3().fromArray(fill.position),
                new THREE.Quaternion().setFromEuler(new THREE.Euler().fromArray(fill.rotation)),
                new THREE.Vector3(1, 1, 1),
            );
            const place = (x, y, z) => {
                const shape = shapes[Math.floor(random() * shapes.length)];
                const size = PhotoStones.SIZE[0] + random() * (PhotoStones.SIZE[1] - PhotoStones.SIZE[0]);
                euler.set(random() * Math.PI, random() * Math.PI, random() * Math.PI);
                quaternion.setFromEuler(euler);
                local.compose(new THREE.Vector3(x, y, z), quaternion, new THREE.Vector3(size, size * (0.7 + random() * 0.3), size));
                matrix.multiplyMatrices(part, local);
                colour.setHex(PhotoStones.SHADES[Math.floor(random() * PhotoStones.SHADES.length)]).multiplyScalar(0.9 + random() * 0.2);
                pieces.push(withColour(shape.clone().applyMatrix4(matrix), colour));
            };
            const jitter = () => (random() - 0.5) * PhotoStones.SPACING * 0.5;
            const columns = Math.max(1, Math.round(width / PhotoStones.SPACING));
            const rows = Math.max(1, Math.round(height / PhotoStones.SPACING));
            for (let c = 0; c < columns; c++) {
                for (let r = 0; r < rows; r++) {
                    const x = -width / 2 + (c + 0.5) * (width / columns);
                    const y = -height / 2 + (r + 0.5) * (height / rows);
                    // against each face, and a second layer behind, staggered, to close the gaps
                    for (const side of [-1, 1]) {
                        place(x + jitter(), y + jitter(), side * (depth / 2 - PhotoStones.SIZE[1] * 0.5) + jitter() * 0.3);
                        place(x + PhotoStones.SPACING / 2 + jitter(), y + PhotoStones.SPACING / 2 + jitter(), side * (depth / 2 - PhotoStones.SIZE[1] * 1.5));
                    }
                }
            }
            // the top: a layer across the depth
            const across = Math.max(1, Math.round(depth / PhotoStones.SPACING));
            for (let c = 0; c < columns; c++) {
                for (let a = 0; a < across; a++) {
                    place(
                        -width / 2 + (c + 0.5) * (width / columns) + jitter(),
                        height / 2 - PhotoStones.SIZE[1] * 0.4,
                        -depth / 2 + (a + 0.5) * (depth / across) + jitter(),
                    );
                }
            }
        }

        const geometry = mergeGeometries(pieces, false);
        pieces.forEach((piece) => piece.dispose());
        shapes.forEach((shape) => shape.dispose());
        const mesh = new THREE.Mesh(geometry, new THREE.MeshStandardMaterial({ vertexColors: true, roughness: 0.82 }));
        mesh.name = 'photo-stones';
        mesh.castShadow = true;
        mesh.receiveShadow = true;

        return mesh;
    }
}

/** A broken stone: an icosahedron with every corner pushed in or out, facets kept (sharp edges). */
function stoneShape(random) {
    const geometry = new THREE.IcosahedronGeometry(1, 1);
    const position = geometry.attributes.position;
    const offsets = new Map();
    for (let i = 0; i < position.count; i++) {
        const key = `${position.getX(i).toFixed(3)}|${position.getY(i).toFixed(3)}|${position.getZ(i).toFixed(3)}`;
        if (!offsets.has(key)) {
            offsets.set(key, 0.75 + random() * 0.4);
        }
        const scale = offsets.get(key);
        position.setXYZ(i, position.getX(i) * scale, position.getY(i) * scale, position.getZ(i) * scale);
    }
    geometry.deleteAttribute('uv');
    geometry.setAttribute('uv', new THREE.BufferAttribute(new Float32Array(position.count * 2), 2));
    geometry.computeVertexNormals();

    return geometry;
}

function withColour(geometry, colour) {
    const count = geometry.attributes.position.count;
    const colours = new Float32Array(count * 4);
    for (let i = 0; i < count; i++) {
        colours.set([colour.r, colour.g, colour.b, 1], i * 4);
    }
    geometry.setAttribute('color', new THREE.BufferAttribute(colours, 4));

    return geometry;
}

function seededRandom(seed) {
    return () => {
        seed |= 0;
        seed = (seed + 0x6d2b79f5) | 0;
        let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;

        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}
