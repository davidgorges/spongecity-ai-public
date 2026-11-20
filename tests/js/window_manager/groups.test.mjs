import { test } from 'node:test';
import assert from 'node:assert/strict';
import { anchor, chainBelow, join, leave, members, normalise, split } from '../../../assets/window_manager/groups.js';

const NEW = () => 'g1';

test('a window on its own moves alone', () => {
    assert.deepEqual(members({ chat: null, map: null }, 'chat'), ['chat']);
});

test('two windows dropped together form a group', () => {
    const groups = join({ chat: null, map: null, details: null }, ['chat', 'map'], NEW);

    assert.deepEqual(groups, { chat: 'g1', map: 'g1', details: null });
    assert.deepEqual(members(groups, 'map'), ['chat', 'map']);
});

test('a window joining one in a group joins that group, and groups merge', () => {
    const groups = join({ chat: 'a', map: 'a', details: 'b', effect: 'b', agent: null }, ['agent', 'details', 'chat'], NEW);

    assert.deepEqual(members(groups, 'agent').sort(), ['agent', 'chat', 'details', 'effect', 'map']);
});

test('a window alone joins nothing', () => {
    const groups = { chat: null };

    assert.equal(join(groups, ['chat'], NEW), groups);
});

test('a window leaving a group of two leaves no group', () => {
    assert.deepEqual(leave({ chat: 'a', map: 'a' }, 'chat'), { chat: null, map: null });
});

test('a window leaving a larger group leaves the others together', () => {
    assert.deepEqual(leave({ chat: 'a', map: 'a', details: 'a' }, 'chat'), { chat: null, map: 'a', details: 'a' });
});

test('groups of one are none', () => {
    assert.deepEqual(normalise({ chat: 'a', map: 'b', details: 'b' }), { chat: null, map: 'b', details: 'b' });
});

test('the anchor of a group is its topmost window, the leftmost of those level', () => {
    const places = { chat: { x: 16, y: 76 }, map: { x: 412, y: 76 }, effect: { x: 16, y: 467 } };

    assert.equal(anchor(['effect', 'map', 'chat'], places), 'chat');
    assert.equal(anchor(['effect', 'map'], places), 'map');
});

test('the windows hanging under one, and under those in turn, form its chain', () => {
    // chat over effect over measures; map beside chat; agent beside effect
    const under = { chat: ['effect'], effect: ['measures'], map: [], measures: [], agent: [] };
    const hangsUnder = (above, below) => under[above].includes(below);

    assert.deepEqual(chainBelow('effect', ['chat', 'map', 'effect', 'measures', 'agent'], hangsUnder), ['effect', 'measures']);
    assert.deepEqual(chainBelow('map', ['chat', 'map', 'effect', 'measures', 'agent'], hangsUnder), ['map']);
});

test('a chain split off forms its own group, the rest stay together', () => {
    const groups = split({ chat: 'a', map: 'a', effect: 'a', measures: 'a' }, ['effect', 'measures'], NEW);

    assert.deepEqual(groups, { chat: 'a', map: 'a', effect: 'g1', measures: 'g1' });
});

test('a window split off alone, and a rest of one, are in no group', () => {
    assert.deepEqual(split({ chat: 'a', effect: 'a' }, ['effect'], NEW), { chat: null, effect: null });
});
