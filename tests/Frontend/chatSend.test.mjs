import test from 'node:test';
import assert from 'node:assert/strict';
import { createSendGate, mergeMessages } from '../../resources/js/lib/chatSend.js';

test('rapid submits cannot acquire the send lock again until the pending send finishes', () => {
    const gate = createSendGate();
    assert.equal(gate.acquire(), true);
    assert.equal(gate.acquire(), false);
    assert.equal(gate.acquire(), false);
    gate.release();
    assert.equal(gate.acquire(), true);
});
test('poll and POST responses for the same message are displayed once and retain server order', () => {
    const user = { id: 1, text: 'Hello' }, bot = { id: 2, text: 'Welcome' };
    assert.deepEqual(mergeMessages([user, bot], [user]), [user, bot]);
    assert.deepEqual(mergeMessages([bot], [user, bot]), [user, bot]);
});
