export function createSendGate() {
    let busy = false;
    return {
        acquire() { if (busy) return false; busy = true; return true; },
        release() { busy = false; },
    };
}

export function mergeMessages(current, incoming) {
    const byId = new Map(current.map((m) => [m.id, m]));
    for (const message of incoming) byId.set(message.id, message);
    return [...byId.values()].sort((a, b) => a.id - b.id);
}

export function newSendId() {
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
