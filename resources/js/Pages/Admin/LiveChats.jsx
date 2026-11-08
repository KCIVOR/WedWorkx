import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { AdminLayout, Panel } from '../../Components/admin/ui';
import { Pill } from '../../Components/wb';
import { chatTime } from '../../lib/chatTime';

export default function LiveChats({ supplierFilter }) {
    const base = `/${usePage().props.admin.path}`;
    const [selected, setSelected] = useState(null);
    const [feed, setFeed] = useState(null);
    const [error, setError] = useState('');
    const log = useRef(null);
    const follow = useRef(true);

    useEffect(() => {
        let stopped = false, timer, controller;
        setFeed(null);
        async function poll() {
            if (document.hidden) { timer = setTimeout(poll, 3000); return; }
            controller = new AbortController();
            const requestTimeout = setTimeout(() => controller.abort(), 10000);
            try {
                const query = new URLSearchParams();
                if (selected) query.set('selected', selected);
                if (supplierFilter) query.set('supplier', supplierFilter);
                const response = await fetch(`${base}/chats/feed?${query}`, {
                    headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal,
                });
                if (response.status === 401 || response.redirected) {
                    if (!stopped) setError('Your admin session ended. Sign in again to continue.');
                    return;
                }
                if (!response.ok) throw new Error('unavailable');
                const data = await response.json();
                if (!stopped) { setFeed(data); setError(''); }
            } catch (e) {
                if (!stopped) setError('Live updates paused. Retrying…');
            } finally {
                clearTimeout(requestTimeout);
            }
            if (!stopped) timer = setTimeout(poll, 3000);
        }
        poll();
        return () => { stopped = true; clearTimeout(timer); controller?.abort(); };
    }, [base, selected, supplierFilter]);

    const conversation = feed?.conversation;
    const lastMessage = conversation?.messages.at(-1)?.id;
    useEffect(() => {
        if (log.current && follow.current) log.current.scrollTop = log.current.scrollHeight;
    }, [selected, lastMessage]);

    return (
        <AdminLayout title="Active Chats" current="/chats">
            <Head title="Active Chats" />
            <p className="text-[14px] text-wb-muted">Watch open Hermes conversations. Updates every 3 seconds. This view cannot send replies.</p>
            {supplierFilter && <Link href={`${base}/chats`} className="text-wb-accent">Show all active chats</Link>}
            {error && <p role="alert" className="text-[14px] text-red-700">{error}</p>}
            <div className="grid items-start gap-s-4 lg:grid-cols-[320px_minmax(0,1fr)]">
                <Panel title="Open sessions">
                    {!feed ? <p className="text-wb-muted">Connecting…</p> : feed.sessions.length === 0 ? <p className="text-[14px] text-wb-muted">No active chats right now.</p> : (
                        <div className="grid gap-s-2">
                            {feed.sessions.map((s) => (
                                <button key={s.id} type="button" aria-pressed={selected === s.id}
                                    onClick={() => { follow.current = true; setSelected(s.id); }}
                                    className={`grid min-w-0 gap-s-2 rounded-r-field border p-s-3 text-left ${selected === s.id ? 'border-wb-accent bg-blue-50' : 'border-wb-line hover:bg-slate-50'}`}>
                                    <strong className="break-words text-wb-heading">{s.name}</strong>
                                    {s.business && <span className="break-words text-[13px]">{s.business}</span>}
                                    <span className="text-[12px] text-wb-muted">{s.bot === 'registration' ? 'Bot 1 · Registration' : 'Bot 2 · Website details'}</span>
                                    <span><Pill tone={s.waiting ? 'warning' : 'success'}>{s.waiting ? 'Waiting for bot' : 'Open'}</Pill></span>
                                </button>
                            ))}
                            {feed.sessions.length === 50 && <p className="text-[12px] text-wb-muted">Showing the 50 most recently active sessions.</p>}
                        </div>
                    )}
                </Panel>
                <Panel title={conversation ? `${conversation.name} · Live chat` : 'Live conversation'}>
                    {!selected ? <p className="text-wb-muted">Select an open session to watch.</p> : !feed ? <p className="text-wb-muted">Loading conversation…</p> : !conversation ? (
                        <p role="status" className="text-wb-muted">This session ended or the client disconnected. It is no longer available in the live view.</p>
                    ) : <>
                        {conversation.supplierId && <Link href={`${base}/suppliers/${conversation.supplierId}`} className="text-[13px] text-wb-accent">Supplier details →</Link>}
                        <div ref={log} role="log" aria-label="Live conversation" aria-live="polite"
                            onScroll={() => { const e = log.current; follow.current = e.scrollHeight - e.scrollTop - e.clientHeight < 60; }}
                            className="mt-s-3 flex h-[60dvh] min-h-[280px] flex-col gap-s-3 overflow-y-auto rounded-r-field bg-slate-50 p-s-3">
                            {conversation.messages.length === 0 && <p className="text-[14px] text-wb-muted">The session is open. Waiting for the first message…</p>}
                            {conversation.messages.map((m) => {
                                const client = m.role === 'supplier', time = chatTime(m.at);
                                return <div key={m.id} className={`grid max-w-[90%] gap-s-1 ${client ? 'self-end' : 'self-start'}`}>
                                    <span className="text-[11px] font-semibold text-wb-muted">{client ? 'Client' : 'WedWorks Assistant'}</span>
                                    <p className={`whitespace-pre-wrap break-words rounded-r-field px-s-3 py-s-2 text-[14px] ${client ? 'bg-wb-accent text-white' : 'border border-wb-line bg-white text-wb-text'}`}>{m.text}</p>
                                    {time && <time dateTime={time.iso} title={time.title} className="text-[11px] text-wb-muted">{time.label}</time>}
                                </div>;
                            })}
                            {conversation.waiting && <p role="status" className="text-[12px] text-wb-muted">Waiting for the assistant to reply…</p>}
                        </div>
                        <p className="mt-s-2 text-[12px] text-wb-muted">View only · Closed sessions leave this page. Disconnected clients disappear within about 90 seconds.</p>
                    </>}
                </Panel>
            </div>
            <p className="text-[12px] text-wb-muted">Fixed-questionnaire and fallback sessions are not included because they do not store a full server transcript.</p>
        </AdminLayout>
    );
}
