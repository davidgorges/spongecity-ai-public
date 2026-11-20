import { test } from 'node:test';
import assert from 'node:assert/strict';
import { join, leave, members, normalise } from '../../../assets/window_manager/groups.js';

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
