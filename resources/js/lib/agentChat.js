import { useCallback, useEffect, useRef, useState } from 'react';
import { isChatPoll, replyTimedOut } from './agentChatRecovery';

/**
 * The supplier's side of a chat the Hermes agent runs. It only sends the supplier's words and shows Hermes' replies.
 * If Hermes cannot be reached (not listening, no answer in time, or any error), `onFallback` runs and the page carries
 * on with the fixed questions, keeping what Hermes had already collected. The supplier is never told why.
 */

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

async function call(method, url, body) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
    const res = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: body ? JSON.stringify(body) : undefined,
        signal: controller.signal,
    });
    let json = null;
    try { json = await res.json(); } catch { /* empty body */ }
    return { ok: res.ok, status: res.status, json };
    } catch {
        return { ok: false, status: 0, json: null };
    } finally {
        clearTimeout(timeout);
    }
}

const POLL_MS = 1500;

/**
 * @param {object}   o
 * @param {boolean}  o.enabled     start a new chat (false still resumes one saved in this browser tab)
 * @param {string}   o.startUrl    where to start the chat
 * @param {string}   o.storageKey  sessionStorage key that remembers the chat id across a reload
 * @param {function} o.onReady     (answers) Hermes collected everything: show the review card
 * @param {function} o.onFallback  (answers) switch to the fixed chat
 */
export function useAgentChat({ enabled, startUrl, storageKey, onReady, onFallback }) {
    const read = () => { try { return sessionStorage.getItem(storageKey); } catch { return null; } };
    const write = (v) => { try { if (v) sessionStorage.setItem(storageKey, v); else sessionStorage.removeItem(storageKey); } catch { /* ignore */ } };

    const [active, setActive] = useState(() => enabled || !!read());
    const [messages, setMessages] = useState([]);
    const [widget, setWidget] = useState(null);
    const [waiting, setWaiting] = useState(true);
    const chatId = useRef(null);
    const lastId = useRef(0);
    const failures = useRef(0);
    const accepted = useRef({});
    const handlers = useRef({ onReady, onFallback });
    handlers.current = { onReady, onFallback };

    const finish = useCallback((kind, answers) => {
        // Keep review's capability until confirmation so reloads resume review and complete() can close it.
        if (kind !== 'ready') { write(null); chatId.current = null; }
        setActive(false);
        if (kind === 'ready') handlers.current.onReady(answers); else handlers.current.onFallback(answers);
    }, []); // eslint-disable-line react-hooks/exhaustive-deps

    useEffect(() => {
        if (!active) return undefined;
        let stopped = false;
        let timer;

        async function poll() {
            if (stopped) return;
            const r = await call('GET', `/agent-chat/${chatId.current}?after=${lastId.current}`);
            if (stopped) return;

            if (!r.ok || !isChatPoll(r.json)) {
                // A broken or ended chat, or the network: three misses in a row and the fixed chat takes over.
                if (r.status === 404 || r.status === 409 || r.status === 403 || ++failures.current >= 6) { finish('fallback', accepted.current); return; }
                timer = setTimeout(poll, POLL_MS);
                return;
            }
            failures.current = 0;
            const d = r.json;
            if (d.answers) accepted.current = d.answers;

            if (d.messages.length) {
                lastId.current = d.messages[d.messages.length - 1].id;
                setMessages((m) => {
                    const seen = new Set(m.map((x) => x.id));
                    return [...m, ...d.messages.filter((x) => !seen.has(x.id))];
                });
            }
            setWidget(d.widget ?? null);
            setWaiting(!!d.awaiting);

            if (d.status === 'review') { finish('ready', d.answers ?? {}); return; }
            if (d.status === 'fallback' || d.status === 'done') { finish('fallback', d.answers ?? {}); return; }

            // Browser handoffs and short reconnects get a small grace window before choosing the fixed form.
            if (replyTimedOut(d.waiting_seconds, d.timeout)) {
                const f = await call('POST', `/agent-chat/${chatId.current}/fallback`);
                if (stopped) return;
                if (f.ok && f.json?.fallback) { finish('fallback', f.json.answers ?? {}); return; }
            }
            timer = setTimeout(poll, POLL_MS);
        }

        async function begin() {
            const saved = read();
            for (let attempt = 0; attempt < 3; attempt++) {
                // The server chooses the canonical invitation/cookie session; browser storage is only a legacy hint.
                const r = await call('POST', startUrl, saved ? { resume_id: saved } : undefined);
                if (stopped) return;
                if (r.ok && !r.json?.fallback && r.json?.chat_id) {
                    chatId.current = r.json.chat_id;
                    accepted.current = r.json.answers ?? {};
                    write(r.json.chat_id);
                    poll();
                    return;
                }
                if (r.status === 403 || r.status === 404 || attempt === 2) { finish('fallback', accepted.current); return; }
                await new Promise((resolve) => { timer = setTimeout(resolve, POLL_MS); });
                if (stopped) return;
            }
        }

        begin();

        return () => { stopped = true; clearTimeout(timer); };
    }, [active]); // eslint-disable-line react-hooks/exhaustive-deps

    const send = useCallback(async (text) => {
        if (!chatId.current || !text.trim()) return false;
        const r = await call('POST', `/agent-chat/${chatId.current}/messages`, { text });
        if (!r.ok) return false;
        lastId.current = Math.max(lastId.current, r.json.message.id);
        setMessages((m) => [...m, r.json.message]);
        setWaiting(true);
        setWidget(null);
        return true;
    }, []);

    /** The supplier confirmed on the review card. */
    const complete = useCallback(() => {
        const id = chatId.current ?? read();
        write(null);
        if (id) call('POST', `/agent-chat/${id}/finish`);
    }, []); // eslint-disable-line react-hooks/exhaustive-deps

    const retry = () => {
        lastId.current = 0;
        failures.current = 0;
        accepted.current = {}; // Keep corrected fixed-form answers in the page; do not replay an older agent cache.
        setMessages([]);
        setWaiting(true);
        setActive(true);
    };
    return { active, messages, widget, waiting, send, complete, retry };
}
