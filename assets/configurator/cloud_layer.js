import * as THREE from 'three';

/**
 * A layer of fair-weather clouds high above the scene: a large plane whose
 * shader draws domain-warped noise, lit from the sun's side and fading out
 * towards the horizon (no visible edge). Static, so it costs one cheap full-
 * screen-ish pass only when a frame is rendered anyway.
 */
export class CloudLayer {
    static ALTITUDE = 900;
    static SIZE = 12000;

    mesh;

    constructor() {
        this.mesh = new THREE.Mesh(
            new THREE.PlaneGeometry(CloudLayer.SIZE, CloudLayer.SIZE).rotateX(Math.PI / 2),
            new THREE.ShaderMaterial({
                uniforms: {
                    sunDirection: { value: new THREE.Vector3(0, 1, 0) },
                    litColour: { value: new THREE.Color(0xffffff) },
                    shadeColour: { value: new THREE.Color(0xb8c4d4) },
                    coverage: { value: 0.42 },
                    opacity: { value: 0.9 },
                },
                vertexShader: /* glsl */ `
                    varying vec3 vWorld;
                    void main() {
                        vec4 world = modelMatrix * vec4(position, 1.0);
                        vWorld = world.xyz;
                        gl_Position = projectionMatrix * viewMatrix * world;
                    }`,
                fragmentShader: /* glsl */ `
                    uniform vec3 sunDirection;
                    uniform vec3 litColour;
                    uniform vec3 shadeColour;
                    uniform float coverage;
                    uniform float opacity;
                    varying vec3 vWorld;

                    float hash(vec2 p) { return fract(sin(dot(p, vec2(127.1, 311.7))) * 43758.5453); }
                    float noise(vec2 p) {
                        vec2 i = floor(p);
                        vec2 f = fract(p);
                        vec2 u = f * f * (3.0 - 2.0 * f);
                        return mix(mix(hash(i), hash(i + vec2(1.0, 0.0)), u.x), mix(hash(i + vec2(0.0, 1.0)), hash(i + vec2(1.0, 1.0)), u.x), u.y);
                    }
                    float fbm(vec2 p) {
                        float value = 0.0;
                        float amplitude = 0.5;
                        for (int i = 0; i < 5; i++) {
                            value += amplitude * noise(p);
                            p = p * 2.03 + vec2(17.0, 9.0);
                            amplitude *= 0.5;
                        }
                        return value;
                    }

                    void main() {
                        vec2 p = vWorld.xz * 0.0011;
                        vec2 warp = vec2(fbm(p + 3.1), fbm(p - 7.7));
                        float density = fbm(p + warp * 1.6);
                        float cloud = smoothstep(coverage, coverage + 0.22, density);
                        if (cloud < 0.01) discard;

                        // lit on the sun's side: sample density a step towards the sun
                        float towardsSun = fbm(p + warp * 1.6 + normalize(sunDirection.xz + 0.0001) * 0.08);
                        float lit = clamp(0.55 + (density - towardsSun) * 4.0, 0.0, 1.0);
                        vec3 colour = mix(shadeColour, litColour, lit);

                        // fade out towards the horizon so the plane has no edge
                        float distance = length(vWorld.xz - cameraPosition.xz);
                        float fade = 1.0 - smoothstep(2500.0, 5500.0, distance);
                        gl_FragColor = vec4(colour, cloud * opacity * fade);
                    }`,
                transparent: true,
                depthWrite: false,
                side: THREE.DoubleSide,
                fog: false,
            }),
        );
        this.mesh.position.y = CloudLayer.ALTITUDE;
        this.mesh.renderOrder = -1;
        this.mesh.name = 'clouds';
        this.mesh.frustumCulled = false;
    }

    /** Colours and sun for a mood (outdoor_environment.js). */
    setLighting(sunDirection, litColour, shadeColour, coverage) {
        const uniforms = this.mesh.material.uniforms;
        uniforms.sunDirection.value.copy(sunDirection);
        uniforms.litColour.value.set(litColour);
        uniforms.shadeColour.value.set(shadeColour);
        uniforms.coverage.value = coverage;
    }

    dispose() {
        this.mesh.geometry.dispose();
        this.mesh.material.dispose();
    }
}
