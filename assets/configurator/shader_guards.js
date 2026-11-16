import { ShaderChunk } from 'three';

/** where three.js (r170) tilts the surface normal by a tangent-space normal map */
const NORMAL_MAP_LINE = 'normal = normalize( tbn * mapN );';

/**
 * A tangent-space normal map turns into NaN where the tangent frame collapses (a leaf of a
 * downloaded bush seen almost edge-on, tiny on screen): normalize() of a zero or NaN vector.
 * One such pixel is invisible on its own, but bloom (ultra tier) blurs it through its mip
 * chain into a black block hundreds of pixels wide. There the surface keeps its own normal.
 *
 * The comparison is false for NaN as well, so no isnan() is needed (which some GPU compilers
 * optimise away).
 */
export function guardNormalMaps() {
    const chunk = ShaderChunk.normal_fragment_maps;
    if (!chunk.includes(NORMAL_MAP_LINE)) {
        console.warn('shader_guards: three.js normal map chunk changed, NaN guard not applied');

        return;
    }
    ShaderChunk.normal_fragment_maps = chunk.replace(NORMAL_MAP_LINE, /* glsl */ `{
        vec3 mapped = tbn * mapN;
        float lengthSq = dot( mapped, mapped );
        normal = lengthSq > 1e-12 ? mapped * inversesqrt( lengthSq ) : nonPerturbedNormal;
    }`);
}
