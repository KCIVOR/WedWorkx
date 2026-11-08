import test from 'node:test';
import assert from 'node:assert/strict';
import { chatTime } from '../../resources/js/lib/chatTime.js';

test('chat times use Philippine time regardless of browser timezone', () => {
    const time = chatTime('2026-11-08T12:05:00Z');
    assert.equal(time.label, '8:05 PM');
    assert.equal(time.iso, '2026-11-08T12:05:00.000Z');
    assert.match(time.title, /Philippine time/);
});
test('missing historical timestamps are hidden instead of invented', () => {
    for (const value of [null, undefined, '', 'invalid']) assert.equal(chatTime(value), null);
});
