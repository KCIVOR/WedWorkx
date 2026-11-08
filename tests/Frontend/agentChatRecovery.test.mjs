import test from 'node:test';
import assert from 'node:assert/strict';
import { shouldStartAgent, replyTimedOut, isChatPoll } from '../../resources/js/lib/agentChatRecovery.js';

test('server-resumable chat takes precedence over an old fixed-questionnaire draft', () => {
    assert.equal(shouldStartAgent({ enabled: true, resume: true }, { draft: { answers: {} } }), true);
    assert.equal(shouldStartAgent({ enabled: true, resume: false }, { draft: { answers: {} } }), false);
    assert.equal(shouldStartAgent({ enabled: true }), true);
});
test('confirmed answers and disabled AI do not start an agent even if a session exists', () => {
    assert.equal(shouldStartAgent({ enabled: true, resume: true }, { saved: true }), false);
    assert.equal(shouldStartAgent({ enabled: false, resume: true }), false);
});
test('a reply timeout allows a fifteen second reconnect grace period', () => {
    assert.equal(replyTimedOut(25, 25), false);
    assert.equal(replyTimedOut(39, 25), false);
    assert.equal(replyTimedOut(40, 25), true);
});

test('malformed successful proxy responses are retryable rather than breaking polling', () => {
    for (const data of [null, {}, '<html>Error</html>', { status: 'open', messages: [null] }]) assert.equal(isChatPoll(data), false);
    assert.equal(isChatPoll({ status: 'open', messages: [{ id: 1, role: 'hermes', text: 'Hello' }], waiting_seconds: 0, timeout: 25 }), true);
});
