import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * The supplier's side of a chat the Hermes agent runs. It only sends the supplier's words and shows Hermes' replies.
 * If Hermes cannot be reached (not listening, no answer in time, or any error), `onFallback` runs and the page carries
 * on with the fixed questions, keeping what Hermes had already collected. The supplier is never told why.
 */

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

async function call(method, url, body) {
    const res = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: body ? JSON.stringify(body) : undefined,
    });
    let json = null;
    try { json = await res.json(); } catch { /* empty body */ }
    return { ok: res.ok, status: res.status, json };
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
    const handlers = useRef({ onReady, onFallback });
    handlers.current = { onReady, onFallback };

    const finish = useCallback((kind, answers) => {
        write(null);
        chatId.current = null;
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

            if (!r.ok) {
                // A broken or ended chat, or the network: three misses in a row and the fixed chat takes over.
                if (r.status === 404 || r.status === 409 || r.status === 403 || ++failures.current >= 3) { finish('fallback', {}); return; }
                timer = setTimeout(poll, POLL_MS);
                return;
            }
            failures.current = 0;
            const d = r.json;

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

            if (d.waiting_seconds >= d.timeout) {
                const f = await call('POST', `/agent-chat/${chatId.current}/fallback`);
                if (stopped) return;
                if (f.ok && f.json?.fallback) { finish('fallback', f.json.answers ?? {}); return; }
            }
            timer = setTimeout(poll, POLL_MS);
        }

        async function begin() {
            const saved = read();
            if (saved) {
                chatId.current = saved;
            } else {
                const r = await call('POST', startUrl);
                if (stopped) return;
                if (!r.ok || r.json?.fallback || !r.json?.chat_id) { finish('fallback', {}); return; }
                chatId.current = r.json.chat_id;
                write(r.json.chat_id);
            }
            poll();
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

    return { active, messages, widget, waiting, send, complete };
}
