import * as THREE from 'three';
import { EffectComposer } from 'three/examples/jsm/postprocessing/EffectComposer.js';
import { OutputPass } from 'three/examples/jsm/postprocessing/OutputPass.js';
import { ShaderPass } from 'three/examples/jsm/postprocessing/ShaderPass.js';
import { UnrealBloomPass } from 'three/examples/jsm/postprocessing/UnrealBloomPass.js';
import { GradeShader } from './post_processing.js';

/**
 * The darkroom of photo mode: turns the path tracer's accumulated HDR image
 * into the finished photograph, every time a sample is drawn.
 *
 *   accumulated samples (linear HDR)
 *     → speckle removal: a pixel brighter or darker than all its neighbours (a
 *       firefly, a lone dark grain) is pulled back into their range; everything
 *       else stays sharp, no blur
 *     → bloom: only what is really bright (sun on glass and metal, lamps, fire)
 *       spills a little light, like a lens does
 *     → tone mapping (the renderer's AgX and exposure) and sRGB
 *     → the live view's grade with a photographic setting: a little more
 *       contrast, a vignette, fine grain that hides the last of the noise
 */
export class PhotoFinish {
    static BLOOM = { strength: 0.18, radius: 0.55, threshold: 1.6 };
    static GRADE = { contrast: 1.1, saturation: 1.06, warmth: 0.03, vignette: 0.28, grain: 0.018 };

    #renderer;
    #composer;
    #despeckle;
    #grade;
    #size;

    constructor(renderer) {
        this.#renderer = renderer;
        const size = renderer.getDrawingBufferSize(new THREE.Vector2());
        this.#size = size.clone();
        const target = new THREE.WebGLRenderTarget(size.x, size.y, { type: THREE.HalfFloatType });
        this.#composer = new EffectComposer(renderer, target);
        this.#composer.setPixelRatio(1);
        this.#composer.setSize(size.x, size.y);

        this.#despeckle = new ShaderPass(DespeckleShader);
        this.#despeckle.textureID = null; // reads the path tracer's target, not the previous pass
        this.#composer.addPass(this.#despeckle);

        const { strength, radius, threshold } = PhotoFinish.BLOOM;
        this.#composer.addPass(new UnrealBloomPass(size, strength, radius, threshold));
        this.#composer.addPass(new OutputPass());

        this.#grade = new ShaderPass(GradeShader);
        Object.entries(PhotoFinish.GRADE).forEach(([key, value]) => { this.#grade.uniforms[key].value = value; });
        this.#composer.addPass(this.#grade);
    }

    /** Draws the finished photo of the accumulated image to the canvas. */
    render(accumulated) {
        const size = this.#renderer.getDrawingBufferSize(new THREE.Vector2());
        if (!size.equals(this.#size)) {
            this.#size.copy(size);
            this.#composer.setSize(size.x, size.y);
        }
        this.#despeckle.uniforms.tDiffuse.value = accumulated.texture;
        this.#grade.uniforms.seed.value = 0; // grain stays put: the photo must not shimmer
        this.#composer.render();
    }

    dispose() {
        this.#composer.dispose();
        this.#composer.renderTarget1.dispose();
        this.#composer.renderTarget2.dispose();
    }
}

/**
 * Removes speckles without blurring: each pixel is clamped, per channel, to the
 * range of its eight neighbours. A lone firefly (a sun glint found by chance on
 * a dark post, a sky reflection in the roof glass) or a lone dark grain falls
 * back into its surroundings; an edge, a grass blade or stone texture has
 * neighbours on both sides and is left as it is.
 *
 * A spatial denoiser (a Gaussian weighted by colour similarity) was tried
 * before: it smeared textures into a painted look, more samples are used instead.
 */
const DespeckleShader = {
    name: 'PhotoDespeckleShader',
    uniforms: {
        tDiffuse: { value: null },
    },
    vertexShader: /* glsl */ `
        varying vec2 vUv;
        void main() {
            vUv = uv;
            gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
        }`,
    fragmentShader: /* glsl */ `
        uniform sampler2D tDiffuse;
        varying vec2 vUv;

        void main() {
            ivec2 last = textureSize(tDiffuse, 0) - 1;
            ivec2 pixel = ivec2(vUv * vec2(last + 1));
            vec3 low = vec3(1e20);
            vec3 high = vec3(0.0);
            for (int x = -1; x <= 1; x++) {
                for (int y = -1; y <= 1; y++) {
                    if (x == 0 && y == 0) continue;
                    vec3 neighbour = texelFetch(tDiffuse, clamp(pixel + ivec2(x, y), ivec2(0), last), 0).rgb;
                    low = min(low, neighbour);
                    high = max(high, neighbour);
                }
            }
            vec3 centre = texelFetch(tDiffuse, pixel, 0).rgb;
            gl_FragColor = vec4(clamp(centre, low, high), 1.0);
        }`,
};
