import * as THREE from 'three';

/**
 * Finds the product part under the pointer and outlines it. The product is
 * drawn as a few merged meshes, so picking works on a list of the parts'
 * boxes instead: a ray test in each part's local space (≈100 parts, far
 * below a millisecond).
 */
export class PartPicker {
    static OUTLINE_COLOUR = 0x5340f4;
    static OUTLINE_MARGIN = 0.006;

    #parts = [];
    #raycaster = new THREE.Raycaster();
    #outline;
    #localRay = new THREE.Ray();
    #hit = new THREE.Vector3();
    #current = null;

    constructor(scene) {
        this.#outline = new THREE.LineSegments(
            new THREE.EdgesGeometry(new THREE.BoxGeometry(1, 1, 1)),
            new THREE.LineBasicMaterial({ color: PartPicker.OUTLINE_COLOUR, depthTest: false, transparent: true, opacity: 0.9 }),
        );
        this.#outline.renderOrder = 10;
        this.#outline.visible = false;
        this.#outline.name = 'part-outline';
        scene.add(this.#outline);
    }

    /** @param {object[]} parts product parts of the scene JSON (with sku) */
    setParts(parts) {
        this.#parts = parts.map((part) => {
            // boxes and wire mats carry their bounding size; an extrusion's comes from its profile
            const extrusion = part.shape === 'extrusion';
            const size = extrusion ? extrusionSize(part) : part.size;
            const offset = extrusion ? extrusionCentre(part) : [0, 0, 0];
            const matrix = new THREE.Matrix4().compose(
                new THREE.Vector3().fromArray(part.position),
                new THREE.Quaternion().setFromEuler(new THREE.Euler().fromArray(part.rotation)),
                new THREE.Vector3(1, 1, 1),
            ).multiply(new THREE.Matrix4().makeTranslation(...offset));

            return {
                label: part.label,
                sku: part.sku,
                matrix,
                inverse: matrix.clone().invert(),
                box: new THREE.Box3(new THREE.Vector3(...size.map((s) => -s / 2)), new THREE.Vector3(...size.map((s) => s / 2))),
                size,
            };
        });
        this.clear();
    }

    /**
     * @param {THREE.Vector2} pointer normalised device coordinates
     * @returns {{label: string, sku: string}|null} the part under the pointer, outlined
     */
    pick(pointer, camera) {
        this.#raycaster.setFromCamera(pointer, camera);
        let nearest = null;
        let nearestDistance = Infinity;
        for (const part of this.#parts) {
            this.#localRay.copy(this.#raycaster.ray).applyMatrix4(part.inverse);
            if (this.#localRay.intersectBox(part.box, this.#hit)) {
                const distance = this.#hit.applyMatrix4(part.matrix).distanceTo(this.#raycaster.ray.origin);
                if (distance < nearestDistance) {
                    nearestDistance = distance;
                    nearest = part;
                }
            }
        }
        if (nearest !== this.#current) {
            this.#current = nearest;
            this.#showOutline(nearest);
        }

        return nearest ? { label: nearest.label, sku: nearest.sku } : null;
    }

    /**
     * World bounds of the parts whose SKU or label contains the text (any case);
     * empty when none does.
     */
    boundsOf(text) {
        const needle = text.toLowerCase();
        const bounds = new THREE.Box3();
        this.#parts
            .filter((part) => (part.sku ?? '').toLowerCase().includes(needle) || (part.label ?? '').toLowerCase().includes(needle))
            .forEach((part) => bounds.union(part.box.clone().applyMatrix4(part.matrix)));

        return bounds;
    }

    clear() {
        this.#current = null;
        this.#outline.visible = false;
    }

    #showOutline(part) {
        this.#outline.visible = Boolean(part);
        if (!part) {
            return;
        }
        const margin = PartPicker.OUTLINE_MARGIN * 2;
        this.#outline.matrixAutoUpdate = false;
        this.#outline.matrix.copy(part.matrix).multiply(new THREE.Matrix4().makeScale(
            part.size[0] + margin, part.size[1] + margin, part.size[2] + margin,
        ));
        this.#outline.matrixWorldNeedsUpdate = true;
    }
}

function extrusionBounds(part) {
    const xs = part.profile.map(([x]) => x);
    const ys = part.profile.map(([, y]) => y);

    return { minX: Math.min(...xs), maxX: Math.max(...xs), minY: Math.min(...ys), maxY: Math.max(...ys) };
}

function extrusionSize(part) {
    const b = extrusionBounds(part);

    return [b.maxX - b.minX, b.maxY - b.minY, part.depth];
}

function extrusionCentre(part) {
    const b = extrusionBounds(part);

    return [(b.minX + b.maxX) / 2, (b.minY + b.maxY) / 2, 0];
}
