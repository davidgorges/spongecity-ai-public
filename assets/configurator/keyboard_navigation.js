import * as THREE from 'three';

/**
 * Walks the camera with the keyboard: W/S forward and back, A/D sideways
 * (along the ground, wherever the camera looks), Q/E turn left and right
 * around the point the camera looks at. Keys are physical (KeyboardEvent.code),
 * so it works on QWERTZ and AZERTY alike. Movement is per second, scaled by
 * the distance to the target, so it feels the same close up and far away.
 */
export const KEY_ACTIONS = {
    KeyW: 'forward',
    KeyS: 'back',
    KeyA: 'left',
    KeyD: 'right',
    KeyQ: 'turnLeft',
    KeyE: 'turnRight',
};

export class KeyboardNavigation {
    static SPEED_PER_DISTANCE = 0.25;
    static MIN_SPEED = 0.8;
    static TURN_SPEED = 0.9;
    /** the target stays within this radius around the origin (the product) */
    static MAX_TARGET_DISTANCE = 30;

    #camera;
    #controls;
    #active = new Set();
    #forward = new THREE.Vector3();
    #right = new THREE.Vector3();
    #move = new THREE.Vector3();
    #up = new THREE.Vector3(0, 1, 0);

    constructor(camera, controls) {
        this.#camera = camera;
        this.#controls = controls;
    }

    /** @returns {boolean} whether the key belongs to the navigation (the caller then prevents its default) */
    press(code) {
        const action = KEY_ACTIONS[code];
        if (action) {
            this.#active.add(action);
        }

        return Boolean(action);
    }

    release(code) {
        const action = KEY_ACTIONS[code];
        if (action) {
            this.#active.delete(action);
        }

        return Boolean(action);
    }

    /** e.g. on blur, when the keyup will never arrive */
    releaseAll() {
        this.#active.clear();
    }

    get moving() {
        return this.#active.size > 0;
    }

    /** Applies the held keys for a frame of `seconds`; returns whether anything moved. */
    step(seconds) {
        if (!this.moving) {
            return false;
        }
        const target = this.#controls.target;
        const distance = this.#camera.position.distanceTo(target);

        this.#camera.getWorldDirection(this.#forward);
        this.#forward.y = 0;
        this.#forward.normalize();
        this.#right.crossVectors(this.#forward, this.#up);

        this.#move.set(0, 0, 0);
        if (this.#active.has('forward')) this.#move.add(this.#forward);
        if (this.#active.has('back')) this.#move.sub(this.#forward);
        if (this.#active.has('right')) this.#move.add(this.#right);
        if (this.#active.has('left')) this.#move.sub(this.#right);
        if (this.#move.lengthSq() > 0) {
            const speed = Math.max(KeyboardNavigation.MIN_SPEED, distance * KeyboardNavigation.SPEED_PER_DISTANCE);
            this.#move.normalize().multiplyScalar(speed * seconds);
            this.#clampToArea(target, this.#move);
            target.add(this.#move);
            this.#camera.position.add(this.#move);
        }

        const turn = (this.#active.has('turnLeft') ? 1 : 0) - (this.#active.has('turnRight') ? 1 : 0);
        if (turn !== 0) {
            const offset = this.#camera.position.clone().sub(target);
            offset.applyAxisAngle(this.#up, turn * KeyboardNavigation.TURN_SPEED * seconds);
            this.#camera.position.copy(target).add(offset);
        }

        return true;
    }

    /** Shortens the step so the target does not leave the garden. */
    #clampToArea(target, step) {
        const next = target.clone().add(step);
        next.y = 0;
        const max = KeyboardNavigation.MAX_TARGET_DISTANCE;
        if (next.length() > max) {
            next.setLength(max);
            step.set(next.x - target.x, 0, next.z - target.z);
        }
    }
}
