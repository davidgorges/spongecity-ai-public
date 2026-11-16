/** the contexts three.js can draw with, best first */
const CONTEXT_TYPES = ['webgl2', 'webgl'];
const LOSE_CONTEXT = 'WEBGL_lose_context';

/**
 * Whether this browser can create a WebGL context. Browsers started with the
 * GPU off and no software rasteriser (some headless cloud browsers report
 * GL_RENDERER "Disabled") cannot, and three.js throws "Error creating WebGL
 * context". The probe context is released right away: browsers cap the live
 * contexts per page.
 *
 * @param {() => {getContext(type: string): ?object}} createCanvas
 */
export function webGlAvailable(createCanvas = () => document.createElement('canvas')) {
    try {
        const canvas = createCanvas();
        for (const type of CONTEXT_TYPES) {
            const gl = canvas.getContext(type);
            if (gl) {
                gl.getExtension?.(LOSE_CONTEXT)?.loseContext();

                return true;
            }
        }
    } catch {
        // a browser that blocks the canvas (privacy settings) cannot draw either
    }

    return false;
}
