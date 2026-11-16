/**
 * Rendering tiers, cheapest first. The PerformanceGovernor moves between them
 * while the camera moves, so every device gets the best picture it can draw
 * smoothly ("progressive enhancement"). `detail` (0–3) is read by the
 * environment for the density of distant scenery and grass. `transmission`
 * renders glass physically (refraction, blur) at the cost of a second scene
 * pass; without it glass is reflective and transparent, which looks nearly
 * the same for thin panes.
 */
export const TIERS = [
    {
        name: 'low',
        transmission: false,
        pixelRatio: 1,
        shadowMapSize: 1024,
        softShadows: false,
        postProcessing: false,
        ambientOcclusion: false,
        bloom: false,
        detail: 0,
    },
    {
        name: 'standard',
        transmission: false,
        pixelRatio: 1.5,
        shadowMapSize: 2048,
        softShadows: true,
        postProcessing: false,
        ambientOcclusion: false,
        bloom: false,
        detail: 1,
    },
    {
        name: 'high',
        transmission: false,
        // with SMAA, 1.5× looks as sharp as 2× on a retina screen at ~55 % of the pixels
        pixelRatio: 1.5,
        shadowMapSize: 4096,
        softShadows: true,
        postProcessing: true,
        ambientOcclusion: true,
        aoSamples: 12,
        bloom: false,
        detail: 2,
    },
    {
        name: 'ultra',
        // physical glass renders the whole scene a second time (grass included):
        // ~3× the cost for a difference hardly visible on thin panes
        transmission: false,
        // 2× would cost ~2.2× the GPU time of 1.5× on a retina Mac (measured with bin/render-loop --perf
        // --dpr=2: 49 ms against 22 ms a frame) for a difference SMAA mostly hides
        pixelRatio: 1.5,
        shadowMapSize: 4096,
        softShadows: true,
        postProcessing: true,
        ambientOcclusion: true,
        aoSamples: 16,
        bloom: true,
        detail: 3,
        // photographed PBR textures (plaster, paving, roof tiles) instead of drawn ones
        photoTextures: true,
    },
];

export const TIER_STANDARD = 1;
export const TIER_ULTRA = TIERS.length - 1;
