/**
 * The site's ground layers lie millimetres apart (InterventionBuilder: the plot at 4 mm, its areas
 * at 7, the measures from 10 mm up), less than the depth buffer resolves from an aerial camera:
 * draped over the terrain they would fight and flicker, the plot's asphalt showing through a lawn.
 * So the depth test pushes each layer back by its place, the lowest the furthest.
 */

/** the sponge city's plot (SpongeCitySku::PLOT) */
export const SKU_PLOT = 'SC-PLOT';
/** a ground area of the plot drawn as it is, a lawn on an asphalt plot (SpongeCitySku::AREA) */
export const SKU_AREA = 'SC-AREA';

/** polygonOffset of the lawn under the whole site: behind the plot, so an asphalt plot is not grassed over */
export const LAWN_OFFSET = 6;

/** polygonOffset (factor and units) per layer: the plot under its areas, the areas under every measure */
export const GROUND_LAYER_OFFSET = Object.freeze({ [SKU_PLOT]: 4, [SKU_AREA]: 2 });

/** how far back a part is drawn in the depth test, 0 for anything that is not a ground layer */
export function groundLayerOffset(part) {
    return part?.draped ? (GROUND_LAYER_OFFSET[part.sku] ?? 0) : 0;
}

/**
 * Pushes a ground layer's material back by its layer, on the material itself: the mood and the photo
 * textures change the materials in place, a copy would miss them. A layer's material is its own (an
 * area is drawn only where its ground differs from the plot's), and the materials are made anew with
 * every scene, before their parts are merged.
 */
export function applyGroundLayer(material, part) {
    const offset = groundLayerOffset(part);
    if (offset > 0) {
        material.polygonOffset = true;
        material.polygonOffsetFactor = offset;
        material.polygonOffsetUnits = offset;
    }

    return material;
}
