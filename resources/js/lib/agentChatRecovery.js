export function shouldStartAgent(agent, { saved = false, draft = null } = {}) {
    return !!agent?.enabled && !saved && (!draft || !!agent.resume);
}

export function replyTimedOut(waitingSeconds, timeout) {
    return waitingSeconds >= timeout + 15;
}

export function isChatPoll(data) {
    return !!data && ['open', 'review', 'done', 'fallback'].includes(data.status)
        && Array.isArray(data.messages) && data.messages.every((m) => m && Number.isInteger(m.id)
            && ['supplier', 'hermes'].includes(m.role) && typeof m.text === 'string')
        && typeof data.waiting_seconds === 'number' && typeof data.timeout === 'number';
}
