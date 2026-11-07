/**
 * Asks the server for a friendlier wording of one question. Only a question key (and, for the sign-up chat,
 * the first name and business name) is sent. Returns the reworded text, or null for ANY problem, in which
 * case the chat simply shows the scripted question. It never throws.
 */
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

// A little longer than the server's own timeout, so the server's fallback normally answers first.
const GIVE_UP_MS = 7000;

export async function fetchWording(url, body, signal) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), GIVE_UP_MS);
    const onAbort = () => controller.abort();
    signal?.addEventListener('abort', onAbort);

    try {
        const res = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            signal: controller.signal,
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify(body),
        });
        if (!res.ok) return null;
        const json = await res.json();

        return json?.reworded && typeof json.text === 'string' && json.text.trim() ? json.text : null;
    } catch {
        return null;
    } finally {
        clearTimeout(timer);
        signal?.removeEventListener('abort', onAbort);
    }
}
