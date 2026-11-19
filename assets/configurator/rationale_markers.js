import * as THREE from 'three';
import { COLOUR_LINE } from './plot_highlight.js';

/** a person took an alternative instead of the agent's pick: the badge says so in amber */
const COLOUR_USER = 0xd97706;
/** the badge's share of the view's height, whatever the distance: a dot, the full badge for the object pointed at or selected */
const DOT_SIZE = 0.016;
const SIZE = 0.036;
const CANVAS = 128;
const STEM_OPACITY = 0.55;
/** in front of every highlight */
const RENDER_ORDER = 20;
export const BY_USER = 'user';

/**
 * Badges over the objects the agent explained (PlanWorkspace::markers): a sparkle in a disc of the
 * app's primary colour, floating over each object on a thin stem. Each is a small dot, so many
 * close together do not cover the yard; the object under the pointer and the selected one get the
 * full badge (emphasize). They are the same size on screen at any
 * distance and never hidden by a crown or a roof. Picking one (objectAt) names its object, so a
 * click on the badge selects it and its reasons open.
 */
export class RationaleMarkers {
    group = new THREE.Group();
    #textures = new Map();
    #materials = new Map();
    #stemMaterial = new THREE.LineBasicMaterial({ color: COLOUR_LINE, transparent: true, opacity: STEM_OPACITY, toneMapped: false });
    /** the ids whose badge is shown in full */
    #emphasized = new Set();

    constructor() {
        this.group.name = 'rationale-markers';
    }

    /**
     * @param {{id: string, x: number, z: number, lift: number, by: string, title: string}[]} markers metres
     * @param {{heightAt: (x: number, z: number) => number}} terrain
     */
    setMarkers(markers, terrain) {
        this.#clear();
        for (const marker of markers) {
            const ground = terrain.heightAt(marker.x, marker.z);
            const top = ground + marker.lift;
            const sprite = new THREE.Sprite(this.#material(marker.by === BY_USER ? COLOUR_USER : COLOUR_LINE));
            sprite.position.set(marker.x, top, marker.z);
            const size = this.#emphasized.has(marker.id) ? SIZE : DOT_SIZE;
            sprite.scale.set(size, size, 1);
            sprite.center.set(0.5, 0);
            sprite.renderOrder = RENDER_ORDER;
            sprite.userData.markerId = marker.id;
            sprite.userData.title = marker.title;
            const stem = new THREE.Line(new THREE.BufferGeometry().setFromPoints([new THREE.Vector3(marker.x, top - marker.lift * 0.6, marker.z), new THREE.Vector3(marker.x, top, marker.z)]), this.#stemMaterial);
            stem.renderOrder = RENDER_ORDER - 1;
            this.group.add(stem, sprite);
        }
    }

    /** the full badge for these objects (the one pointed at, the selected one), a dot for the rest */
    emphasize(ids) {
        this.#emphasized = new Set(ids.filter(Boolean));
        this.group.children.filter((child) => child.isSprite).forEach((sprite) => {
            const size = this.#emphasized.has(sprite.userData.markerId) ? SIZE : DOT_SIZE;
            sprite.scale.set(size, size, 1);
        });
    }

    /**
     * @returns {{id: string, title: string}|null} the badge under the ray
     */
    objectAt(raycaster, camera) {
        const sprites = this.group.children.filter((child) => child.isSprite);
        if (sprites.length === 0) {
            return null;
        }
        // sprites of a fixed screen size are picked against the camera the view draws with
        raycaster.camera = camera;
        const hit = raycaster.intersectObjects(sprites, false)[0];

        return hit ? { id: hit.object.userData.markerId, title: hit.object.userData.title } : null;
    }

    dispose() {
        this.#clear();
        this.#materials.forEach((material) => material.dispose());
        this.#textures.forEach((texture) => texture.dispose());
        this.#stemMaterial.dispose();
    }

    #clear() {
        this.group.children.filter((child) => child.isLine).forEach((line) => line.geometry.dispose());
        this.group.clear();
    }

    #material(colour) {
        if (!this.#materials.has(colour)) {
            this.#materials.set(colour, new THREE.SpriteMaterial({
                map: this.#texture(colour), sizeAttenuation: false, depthTest: false, depthWrite: false, transparent: true, toneMapped: false,
            }));
        }

        return this.#materials.get(colour);
    }

    /** a disc with a white rim and a four-pointed sparkle */
    #texture(colour) {
        if (!this.#textures.has(colour)) {
            const canvas = document.createElement('canvas');
            canvas.width = canvas.height = CANVAS;
            const ctx = canvas.getContext('2d');
            const middle = CANVAS / 2;
            ctx.beginPath();
            ctx.arc(middle, middle, CANVAS * 0.44, 0, Math.PI * 2);
            ctx.fillStyle = `#${new THREE.Color(colour).getHexString()}`;
            ctx.shadowColor = 'rgba(0, 0, 0, 0.35)';
            ctx.shadowBlur = CANVAS * 0.06;
            ctx.fill();
            ctx.shadowBlur = 0;
            ctx.lineWidth = CANVAS * 0.05;
            ctx.strokeStyle = '#ffffff';
            ctx.stroke();
            ctx.fillStyle = '#ffffff';
            sparkle(ctx, middle - CANVAS * 0.05, middle + CANVAS * 0.04, CANVAS * 0.22);
            sparkle(ctx, middle + CANVAS * 0.17, middle - CANVAS * 0.17, CANVAS * 0.09);
            const texture = new THREE.CanvasTexture(canvas);
            texture.colorSpace = THREE.SRGBColorSpace;
            this.#textures.set(colour, texture);
        }

        return this.#textures.get(colour);
    }
}

function sparkle(ctx, x, y, radius) {
    const waist = radius * 0.28;
    ctx.beginPath();
    ctx.moveTo(x, y - radius);
    ctx.quadraticCurveTo(x + waist, y - waist, x + radius, y);
    ctx.quadraticCurveTo(x + waist, y + waist, x, y + radius);
    ctx.quadraticCurveTo(x - waist, y + waist, x - radius, y);
    ctx.quadraticCurveTo(x - waist, y - waist, x, y - radius);
    ctx.fill();
}
