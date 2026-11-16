<?php

declare(strict_types=1);

namespace Core\Configurator\Scene;

/**
 * Surface patterns the browser generates itself (assets/configurator/procedural_textures.js),
 * so a product needs no image assets. Shared by every product family.
 */
enum ProceduralTexture: string
{
    /** large-format concrete or stone slabs with joints */
    case Paving = 'paving';
    /** fine plaster render of a house wall */
    case Plaster = 'plaster';
    /** wooden boards along the width (decking, cladding) */
    case Planks = 'planks';
    /** rows of flat concrete roof tiles */
    case RoofTiles = 'roof_tiles';
    /** a lit room behind a window: warm gradient, curtain, lamp; drives the night glow */
    case Interior = 'interior';
    /** subtle grain of a powder coating, mostly felt in the highlights */
    case PowderCoat = 'powder_coat';
    /** packed broken stones, as in a gabion basket */
    case Stones = 'stones';
    /** fine brushed grain along the width, without joints (WPC, timber boards) */
    case WoodGrain = 'wood_grain';
    /** rendered wall with a punched window per storey and bay (Site\Facade, one repeat = 3 m) */
    case FacadePlaster = 'facade_plaster';
    /** facing brick with a punched window per storey and bay */
    case FacadeBrick = 'facade_brick';
    /** a continuous window band per storey, parapet panels between */
    case FacadeRibbon = 'facade_ribbon';
    /** vertical profiled metal sheets with a strip of high windows */
    case FacadeCladding = 'facade_cladding';
    /** worn asphalt: fine grain, darker tyre tracks, the odd patch */
    case Asphalt = 'asphalt';
    /** gravel on a flat roof, with faint membrane seams */
    case RoofGravel = 'roof_gravel';
}
