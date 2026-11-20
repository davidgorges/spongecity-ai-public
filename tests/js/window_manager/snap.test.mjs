import { test } from 'node:test';
import assert from 'node:assert/strict';
import { hangsUnder, snapMove, snapResize, toGrid, touching } from '../../../assets/window_manager/snap.js';

const OPTIONS = { threshold: 10, gap: 12 };
const BOUNDS = { left: 16, top: 76, right: 1184, bottom: 728 };
const CHAT = { x: 16, y: 76, w: 384, h: 400 };

test('rounds to the nearest grid line', () => {
    assert.equal(toGrid(23, 16), 16);
    assert.equal(toGrid(25, 16), 32);
});

test('a window dragged near another snaps beside it, a gap apart', () => {
    const moved = { x: 409, y: 300, w: 300, h: 200 };
    const snap = snapMove([moved], [CHAT], BOUNDS, OPTIONS);

    assert.equal(moved.x + snap.x.delta, CHAT.x + CHAT.w + OPTIONS.gap);
    assert.equal(snap.x.guide, 412);
});

test('a window dragged below another lines up with its left edge and snaps under it', () => {
    const moved = { x: 22, y: 484, w: 300, h: 200 };
    const snap = snapMove([moved], [CHAT], BOUNDS, OPTIONS);

    assert.equal(moved.x + snap.x.delta, CHAT.x);
    assert.equal(moved.y + snap.y.delta, CHAT.y + CHAT.h + OPTIONS.gap);
});

test('nothing near: no correction, so the grid takes over', () => {
    const snap = snapMove([{ x: 600, y: 300, w: 200, h: 100 }], [CHAT], BOUNDS, OPTIONS);

    assert.equal(snap.x.delta, null);
    assert.equal(snap.y.delta, null);
});

test('a window far away on the other axis is not snapped to', () => {
    // level with the chat's right edge, but far below it
    const snap = snapMove([{ x: 409, y: 650, w: 200, h: 60 }], [{ ...CHAT, h: 100 }], BOUNDS, OPTIONS);

    assert.equal(snap.x.delta, null);
});

test('a window snaps to the edges of the view', () => {
    const snap = snapMove([{ x: 900, y: 200, w: 280, h: 100 }], [], BOUNDS, OPTIONS);

    assert.equal(snap.x.delta, 4);
    assert.equal(snap.x.guide, BOUNDS.right);
});

test('a group snaps by whichever of its windows is nearest', () => {
    const lead = { x: 600, y: 300, w: 200, h: 100 };
    const follower = { x: 600, y: 412, w: 200, h: 100 };
    const target = { x: 300, y: 420, w: 288, h: 100 };
    const snap = snapMove([lead, follower], [target], BOUNDS, OPTIONS);

    // the follower lands beside the target; the lead moves by the same
    assert.equal(follower.x + snap.x.delta, target.x + target.w + OPTIONS.gap);
});

test('a window resized by its corner snaps its right and bottom edges', () => {
    const snap = snapResize({ x: 16, y: 76, w: 300, h: 395 }, [{ x: 330, y: 76, w: 300, h: 400 }], BOUNDS, OPTIONS);

    assert.equal(snap.x.delta, 2);
    assert.equal(snap.y.delta, 5);
});

test('windows a gap apart and overlapping along that side touch', () => {
    assert.ok(touching(CHAT, { x: 412, y: 200, w: 200, h: 100 }, 12, 1));
    assert.ok(touching(CHAT, { x: 100, y: 488, w: 200, h: 100 }, 12, 1));
    assert.ok(touching({ x: 412, y: 200, w: 200, h: 100 }, CHAT, 12, 1));
});

test('windows further apart, or only meeting at a corner, do not touch', () => {
    assert.ok(!touching(CHAT, { x: 420, y: 200, w: 200, h: 100 }, 12, 1));
    assert.ok(!touching(CHAT, { x: 412, y: 488, w: 200, h: 100 }, 12, 1));
});

test('a window a gap under another, overlapping across, hangs under it', () => {
    assert.ok(hangsUnder(CHAT, { x: 100, y: 488, w: 200, h: 100 }, 12, 1));
    assert.ok(!hangsUnder({ x: 100, y: 488, w: 200, h: 100 }, CHAT, 12, 1));
    assert.ok(!hangsUnder(CHAT, { x: 412, y: 200, w: 200, h: 100 }, 12, 1), 'beside it, not under');
});

test('with no gap, a window dragged near another snaps onto its edge, touching it', () => {
    const moved = { x: 405, y: 100, w: 300, h: 200 };
    const snap = snapMove([moved], [CHAT], BOUNDS, { threshold: 10, gap: 0 });

    assert.equal(moved.x + snap.x.delta, CHAT.x + CHAT.w);
    assert.ok(touching(CHAT, { ...moved, x: CHAT.x + CHAT.w }, 0, 1));
    assert.ok(hangsUnder(CHAT, { x: 16, y: CHAT.y + CHAT.h, w: 300, h: 100 }, 0, 1));
});
