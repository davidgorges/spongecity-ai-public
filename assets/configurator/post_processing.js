import * as THREE from 'three';
import { EffectComposer } from 'three/examples/jsm/postprocessing/EffectComposer.js';
import { RenderPass } from 'three/examples/jsm/postprocessing/RenderPass.js';
import { GTAOPass } from 'three/examples/jsm/postprocessing/GTAOPass.js';
import { UnrealBloomPass } from 'three/examples/jsm/postprocessing/UnrealBloomPass.js';
import { OutputPass } from 'three/examples/jsm/postprocessing/OutputPass.js';
import { ShaderPass } from 'three/examples/jsm/postprocessing/ShaderPass.js';
import { SMAAPass } from 'three/examples/jsm/postprocessing/SMAAPass.js';

/**
 * Colour grade on the display-referred image: a touch of warmth in the
 * highlights, cooler shadows, gentle contrast and a soft vignette, the look
 * of an architectural photograph rather than a raw render. `grain` adds fine
 * film grain (photo mode; 0 in the live view).
 */
export const GradeShader = {
    name: 'GradeShader',
    uniforms: {
        tDiffuse: { value: null },
        vignette: { value: 0.22 },
        contrast: { value: 1.06 },
        saturation: { value: 1.08 },
        warmth: { value: 0.035 },
        grain: { value: 0 },
        seed: { value: 0 },
    },
    vertexShader: /* glsl */ `
        varying vec2 vUv;
        void main() {
            vUv = uv;
            gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
        }`,
    fragmentShader: /* glsl */ `
        uniform sampler2D tDiffuse;
        uniform float vignette;
        uniform float contrast;
        uniform float saturation;
        uniform float warmth;
        uniform float grain;
        uniform float seed;
        varying vec2 vUv;

        float hash(vec2 p) {
            vec3 p3 = fract(vec3(p.xyx) * 0.1031);
            p3 += dot(p3, p3.yzx + 33.33);
            return fract((p3.x + p3.y) * p3.z);
        }

        void main() {
            vec4 texel = texture2D(tDiffuse, vUv);
            vec3 colour = texel.rgb;

            float luma = dot(colour, vec3(0.2126, 0.7152, 0.0722));
            colour = mix(vec3(luma), colour, saturation);
            colour = (colour - 0.5) * contrast + 0.5;

            // split toning: warm highlights, slightly cool shadows
            colour += warmth * vec3(1.0, 0.45, -0.6) * smoothstep(0.45, 1.0, luma);
            colour += warmth * vec3(-0.4, 0.0, 0.6) * (1.0 - smoothstep(0.0, 0.4, luma));

            vec2 centred = vUv - 0.5;
            colour *= 1.0 - vignette * smoothstep(0.35, 0.85, length(centred * vec2(1.1, 1.0)));

            // grain is strongest in the mid-tones, like film
            float midtones = 4.0 * luma * (1.0 - luma);
            colour += grain * midtones * (hash(gl_FragCoord.xy + seed) - 0.5) * 2.0;

            gl_FragColor = vec4(clamp(colour, 0.0, 1.0), texel.a);
        }`,
};

const AO_RESOLUTION = 0.5;

/**
 * Builds the effect chain for a quality tier (see quality_tiers.js):
 * scene → ambient occlusion → bloom → tone mapping/sRGB → grade → SMAA.
 */
export function createComposer(renderer, scene, camera, tier, width, height) {
    const pixelRatio = renderer.getPixelRatio();
    const target = new THREE.WebGLRenderTarget(width * pixelRatio, height * pixelRatio, { type: THREE.HalfFloatType });
    const composer = new EffectComposer(renderer, target);
    composer.addPass(new RenderPass(scene, camera));

    if (tier.ambientOcclusion) {
        const ao = new GTAOPass(scene, camera, width, height);
        // ambient occlusion is soft and low-frequency: half resolution costs a quarter and looks the same
        const setSize = ao.setSize.bind(ao);
        ao.setSize = (w, h) => setSize(Math.ceil(w * AO_RESOLUTION), Math.ceil(h * AO_RESOLUTION));
        ao.updateGtaoMaterial({ radius: 0.4, distanceExponent: 1.6, thickness: 1.2, scale: 1.25, samples: tier.aoSamples });
        ao.updatePdMaterial({ lumaPhi: 10, depthPhi: 2, normalPhi: 3, radius: 6, rings: 2, samples: tier.aoSamples });
        ao.blendIntensity = 0.95;
        // light effects (fire halo, flames, sparks) are see-through: the AO pass would draw
        // them as solid surfaces and darken everything behind them
        const renderAo = ao.render.bind(ao);
        ao.render = (...args) => {
            const effects = scene.userData.withoutAmbientOcclusion ?? [];
            const visible = effects.map((object) => object.visible);
            effects.forEach((object) => { object.visible = false; });
            renderAo(...args);
            effects.forEach((object, i) => { object.visible = visible[i]; });
        };
        composer.addPass(ao);
    }
    if (tier.bloom) {
        // only really bright things glow: LEDs, sun glints on glass and metal
        composer.addPass(new UnrealBloomPass(new THREE.Vector2(width, height), 0.35, 0.6, 0.92));
    }
    composer.addPass(new OutputPass());
    composer.addPass(new ShaderPass(GradeShader));
    composer.addPass(new SMAAPass(width * pixelRatio, height * pixelRatio));

    return composer;
}
