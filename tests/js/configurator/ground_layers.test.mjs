import { test } from 'node:test';
import assert from 'node:assert/strict';
import { applyGroundLayer, GROUND_LAYER_OFFSET, LAWN_OFFSET, groundLayerOffset, SKU_AREA, SKU_PLOT } from '../../../assets/configurator/ground_layers.js';

const material = () => ({ polygonOffset: false, polygonOffsetFactor: 0, polygonOffsetUnits: 0 });

test('the plot lies furthest back, its areas in front of it, measures in front of both', () => {
    const plot = groundLayerOffset({ sku: SKU_PLOT, draped: true });
    const area = groundLayerOffset({ sku: SKU_AREA, draped: true });
    const measure = groundLayerOffset({ sku: 'SC-PLANTING', draped: true });

    assert.ok(plot > area, 'a lawn area wins over the asphalt plot under it');
    assert.ok(area > measure, 'a measure on the lawn wins over the lawn');
    assert.equal(measure, 0);
});

test('a ground layer is pushed back on its own material', () => {
    const lawn = material();

    const drawn = applyGroundLayer(lawn, { sku: SKU_AREA, draped: true });

    assert.equal(drawn, lawn, 'the same material: the mood and the photo textures still reach it');
    assert.equal(lawn.polygonOffset, true);
    assert.equal(lawn.polygonOffsetFactor, GROUND_LAYER_OFFSET[SKU_AREA]);
    assert.equal(lawn.polygonOffsetUnits, GROUND_LAYER_OFFSET[SKU_AREA]);
});

test('what is not a draped ground layer is left as it is', () => {
    for (const part of [{ sku: 'SC-TREE', draped: false }, { sku: SKU_PLOT, draped: false }, { sku: 'SC-PLANTING', draped: true }, null]) {
        const own = material();
        applyGroundLayer(own, part);
        assert.deepEqual(own, material(), JSON.stringify(part));
    }
});

test('the lawn under the site lies behind the plot, so an asphalt plot is not grassed over', () => {
    assert.ok(LAWN_OFFSET > groundLayerOffset({ sku: SKU_PLOT, draped: true }));
});
