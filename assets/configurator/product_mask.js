import * as THREE from 'three';

/**
 * The product's silhouette in the current view, as an image-edit mask for AI image generation:
 * the product (parts with a SKU) opaque, everything else transparent. An image-edit model may
 * repaint the transparent area (scenery, sky) and should keep the opaque one, so the configured
 * product survives; the same mask lets the server lay the original product pixels back on top.
 *
 * The scene is drawn once more without post-processing: only the product group, unlit white on
 * black, then turned into alpha. Visibility, background and fog are restored afterwards.
 */
export class ProductMask {
    static #WHITE = new THREE.MeshBasicMaterial({ color: 0xffffff, toneMapped: false, side: THREE.DoubleSide });
    static #BLACK = new THREE.Color(0x000000);

    /**
     * @param {THREE.WebGLRenderer} renderer
     * @param {THREE.Scene} scene
     * @param {THREE.Camera} camera
     * @param {THREE.Object3D} product the group holding the product's meshes (a direct child of the scene)
     * @returns {Promise<Blob>} PNG, the size of the renderer's canvas
     */
    static async render(renderer, scene, camera, product) {
        const hidden = scene.children.filter((child) => child !== product && child.visible);
        const { background, fog, overrideMaterial } = scene;
        const toneMapping = renderer.toneMapping;
        const clearColor = renderer.getClearColor(new THREE.Color());
        const clearAlpha = renderer.getClearAlpha();

        hidden.forEach((child) => { child.visible = false; });
        scene.background = ProductMask.#BLACK;
        scene.fog = null;
        scene.overrideMaterial = ProductMask.#WHITE;
        renderer.toneMapping = THREE.NoToneMapping;
        renderer.setClearColor(ProductMask.#BLACK, 1);
        try {
            renderer.render(scene, camera);

            return ProductMask.#toAlpha(renderer.domElement);
        } finally {
            hidden.forEach((child) => { child.visible = true; });
            scene.background = background;
            scene.fog = fog;
            scene.overrideMaterial = overrideMaterial;
            renderer.toneMapping = toneMapping;
            renderer.setClearColor(clearColor, clearAlpha);
        }
    }

    /** Copies the frame just drawn (synchronously, before the buffer is cleared) and turns brightness into alpha. */
    static #toAlpha(source) {
        const canvas = document.createElement('canvas');
        canvas.width = source.width;
        canvas.height = source.height;
        const context = canvas.getContext('2d');
        context.drawImage(source, 0, 0);
        const image = context.getImageData(0, 0, canvas.width, canvas.height);
        const pixels = image.data;
        for (let i = 0; i < pixels.length; i += 4) {
            pixels[i + 3] = pixels[i]; // white product → opaque, black rest → transparent
            pixels[i] = pixels[i + 1] = pixels[i + 2] = 255;
        }
        context.putImageData(image, 0, 0);

        return new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
    }
}
