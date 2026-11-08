import IntakeInput from './IntakeInput';
import { intakeFields, normalizeIntakePatch } from '../lib/websiteIntake';
import { shouldStartAgent } from '../lib/agentChatRecovery';
import { useEffect, useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import {
    Actions, AiBubble, Alert, Button, Card, CardIcon, CardText, CardTitle, Chip, ChipRow, FieldError,
    Header, Icon, InputBar, InputZone, Log, Note, Pill, QuickReply, QuickReplyRow, ReviewRow, ReviewRows,
    Shell, Typing, UserBubble,
} from './wb';
import { Countdown, Field, SlotMeter, TextInput, Textarea, Thumb } from './wb/forms';
import { FailedCard, PaymentCard, SavedLaterCard, startPayment } from './PaymentCards';
import { summarize } from '../lib/summarize';
import { fetchWording } from '../lib/wording';
import { useAgentChat } from '../lib/agentChat';
import { firstUnansweredIndex, resumeQuestionIndex } from '../lib/questionSequence';

/* Chatbot 2. The questions come from the server (App\Support\WebsiteQuestions, rows in website_questions, edited in Admin > Questions). */

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

async function api(method, url, body) {
    const res = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        body,
    });
    let json = null;
    try { json = await res.json(); } catch { /* empty body */ }
    return { ok: res.ok, status: res.status, json };
}

const errorFrom = (r) =>
    r.json?.errors?.file?.[0]
    ?? r.json?.errors?.kind?.[0]
    ?? (r.status === 403 ? 'These details can no longer be changed.' : null)
    ?? (r.status === 419 ? 'Your session expired. Please reload the page.' : null)
    ?? (r.status === 413 ? 'That file is too large.' : null)
    ?? 'Something went wrong. Please try again.';

/* ---------------- input pieces, one per question type ---------------- */

function TextareaInput({ q, initial, onSubmit, error }) {
    const [v, setV] = useState(initial ?? '');
    return (
        <div className="grid gap-s-3">
            <Textarea
                value={v}
                onChange={(e) => setV(e.target.value)}
                placeholder={q.placeholder ?? ''}
                aria-label={q.label}
                autoFocus
                invalid={!!error}
            />
            <div className="flex flex-wrap gap-s-2">
                <Button onClick={() => onSubmit(v)}>Send <Icon name="send" /></Button>
                {q.optional && <QuickReply tone="no" onClick={() => onSubmit('')}>Skip</QuickReply>}
            </div>
        </div>
    );
}

function ServicesInput({ q, initial, onSubmit }) {
    const [rows, setRows] = useState(initial?.length ? initial : [{ name: '', price: '' }]);
    const set = (i, k, val) => setRows((r) => r.map((x, j) => (j === i ? { ...x, [k]: val } : x)));
    return (
        <div className="grid gap-s-3">
            {rows.map((r, i) => (
                <div key={i} className="grid grid-cols-[minmax(0,1fr)_110px_auto] items-center gap-s-2">
                    <TextInput value={r.name} onChange={(e) => set(i, 'name', e.target.value)} placeholder="Service or package name" aria-label={`Service ${i + 1} name`} />
                    <TextInput value={r.price} onChange={(e) => set(i, 'price', e.target.value)} placeholder="Price" aria-label={`Service ${i + 1} price (optional)`} />
                    <button
                        type="button"
                        aria-label={`Remove service ${i + 1}`}
                        onClick={() => setRows((x) => (x.length > 1 ? x.filter((_, j) => j !== i) : [{ name: '', price: '' }]))}
                        className="grid h-[36px] w-[36px] place-items-center rounded-full text-[16px] text-wb-muted hover:bg-blue-50 hover:text-wb-accent"
                    >
                        <Icon name="x" />
                    </button>
                </div>
            ))}
            <div>
                <Button variant="ghost" onClick={() => setRows((r) => [...r, { name: '', price: '' }])}>+ Add another service/package</Button>
            </div>
            <div className="flex flex-wrap gap-s-2">
                <Button onClick={() => onSubmit(rows)}>Continue <Icon name="send" /></Button>
                {q.optional && <QuickReply tone="no" onClick={() => onSubmit([])}>Skip</QuickReply>}
            </div>
        </div>
    );
}

function AreasInput({ q, initialAreas, initialBooking, onSubmit }) {
    const [areas, setAreas] = useState(initialAreas);
    const [draft, setDraft] = useState('');
    const [booking, setBooking] = useState(initialBooking ?? '');
    const add = () => {
        const v = draft.trim();
        if (v && !areas.includes(v)) setAreas((a) => [...a, v]);
        setDraft('');
    };
    return (
        <div className="grid gap-s-3">
            {areas.length > 0 && (
                <ChipRow>
                    {areas.map((a) => (
                        <Chip key={a} selected onClick={() => setAreas((x) => x.filter((y) => y !== a))} aria-label={`Remove ${a}`}>
                            {a} ✕
                        </Chip>
                    ))}
                </ChipRow>
            )}
            <div className="flex gap-s-2">
                <TextInput
                    value={draft}
                    onChange={(e) => setDraft(e.target.value)}
                    onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); add(); } }}
                    placeholder="Add another area served"
                    aria-label="Add another area served"
                />
                <Button variant="outline" onClick={add}>+ Add</Button>
            </div>
            <Field label="How should couples contact or book you?">
                <TextInput value={booking} onChange={(e) => setBooking(e.target.value)} placeholder="e.g. Facebook Messenger, phone call, inquiry form..." />
            </Field>
            <div className="flex flex-wrap gap-s-2">
                <Button onClick={() => onSubmit({ service_areas: draft.trim() && !areas.includes(draft.trim()) ? [...areas, draft.trim()] : areas, booking })}>
                    Continue <Icon name="send" />
                </Button>
                {q.optional && <QuickReply tone="no" onClick={() => onSubmit({ service_areas: [], booking: '' })}>Skip</QuickReply>}
            </div>
        </div>
    );
}

function MediaInput({ q, initialColors, uploads, limits, token, onUploaded, onRemoved, onSubmit, error, setError, uploadsOnly = false }) {
    const [colors, setColors] = useState(initialColors ?? '');
    const [busy, setBusy] = useState(false);
    const photoRef = useRef(null);
    const logoRef = useRef(null);
    const logos = uploads.filter((u) => u.kind === 'logo').length;
    const photos = uploads.filter((u) => u.kind === 'photo').length;

    async function upload(files, kind) {
        setError('');
        setBusy(true);
        let logoCount = logos;
        let photoCount = photos;
        for (const file of files) {
            // Quick checks for a fast message. The server checks again and is the real gate.
            if (!limits.mimetypes.includes(file.type)) { setError('Only JPG, PNG or WebP images are allowed.'); break; }
            if (file.size > limits.maxKb * 1024) { setError(`That file is larger than ${Math.round((limits.maxKb / 1024) * 10) / 10} MB. Please choose a smaller one.`); break; }
            if (kind === 'logo' && logoCount >= limits.maxLogo) { setError(`You can add only ${limits.maxLogo} logo. Remove the current one first.`); break; }
            if (kind === 'photo' && photoCount >= limits.maxPhotos) { setError(`You can add up to ${limits.maxPhotos} photos. Remove one first.`); break; }

            const body = new FormData();
            body.append('kind', kind);
            body.append('file', file);
            const r = await api('POST', `/invite/${token}/uploads`, body);
            if (!r.ok) { setError(errorFrom(r)); break; }
            onUploaded(r.json);
            if (kind === 'logo') logoCount += 1; else photoCount += 1;
        }
        setBusy(false);
    }

    async function remove(u) {
        setError('');
        const r = await api('DELETE', `/invite/${token}/uploads/${u.id}`);
        if (r.ok) onRemoved(u.id); else setError(errorFrom(r));
    }

    const accept = limits.mimetypes.join(',');
    return (
        <div className="grid gap-s-3">
            <input ref={photoRef} type="file" accept={accept} multiple hidden onChange={(e) => { upload([...e.target.files], 'photo'); e.target.value = ''; }} />
            <input ref={logoRef} type="file" accept={accept} hidden onChange={(e) => { upload([...e.target.files], 'logo'); e.target.value = ''; }} />
            {uploads.length > 0 && (
                <div className="flex flex-wrap gap-s-3 pt-[6px]">
                    {uploads.map((u) => <Thumb key={u.id} upload={u} disabled={busy} onRemove={() => remove(u)} />)}
                </div>
            )}
            <QuickReplyRow>
                <QuickReply tone="no" disabled={busy || logos >= limits.maxLogo} onClick={() => logoRef.current?.click()}>
                    {logos ? 'Logo added' : 'Add logo'}
                </QuickReply>
                {q.optional && !uploadsOnly && <QuickReply tone="no" disabled={busy} onClick={() => onSubmit('')}>Skip</QuickReply>}
            </QuickReplyRow>
            <div className="text-[12.5px] text-wb-muted tabular-nums">
                {photos} of {limits.maxPhotos} photos · JPG, PNG or WebP, up to {Math.round((limits.maxKb / 1024) * 10) / 10} MB each.
                {uploadsOnly ? 'Add available photos and/or an optional logo, then confirm permission separately.' : 'Use the paperclip to add photos, and type your favorite colors if you like.'}
            </div>
            {uploadsOnly ? <div className="flex flex-wrap gap-s-2"><Button variant="outline" disabled={busy || photos >= limits.maxPhotos} onClick={() => photoRef.current?.click()}>Add photos</Button><Button disabled={busy} onClick={() => { if (!uploads.length) { setError('Upload an available photo or logo, or go back and choose provide later or stock.'); return; } setError(''); onSubmit(uploads.filter((u) => u.kind === 'photo').map((u) => u.id)); }}>Continue</Button></div> : <InputBar
                value={colors}
                onChange={(v) => { setColors(v); setError(''); }}
                onSubmit={() => onSubmit(colors)}
                placeholder={q.placeholder}
                label="Favorite colors"
                onAttach={() => photoRef.current?.click()}
                attachDisabled={busy || photos >= limits.maxPhotos}
                disabled={busy}
            />}
            {busy && <div className="px-s-2 text-[12.5px] text-wb-muted" role="status">Uploading…</div>}
        </div>
    );
}

/* ---------------- the chat ---------------- */

export default function WebsiteChat({ chat, payment, firstName, businessName, registrationContacts = chat.registrationContacts ?? {} }) {
    const { token, questions, saved, defaultArea, limits, expiresAt, serverNow, slot, ai, agent } = chat;
    const draftKey = `wedworks.website.draft.${token.slice(0, 16)}`;

    const draft = useMemo(() => {
        try {
            const d = JSON.parse(sessionStorage.getItem(draftKey));
            if (d && typeof d.answers === 'object' && Number.isInteger(d.idx)) return d;
        } catch { /* storage may be unavailable */ }
        return null;
    }, [draftKey]);

    const [answers, setAnswers] = useState(saved ?? draft?.answers ?? {});
    const [idx, setIdx] = useState(() => saved ? questions.length : resumeQuestionIndex(questions, draft));
    const [phase, setPhase] = useState(() => !saved && agent?.resume ? 'chat' : saved && payment.lastFailed ? 'saved' : saved || resumeQuestionIndex(questions, draft) >= questions.length ? 'review' : 'chat'); // chat | review | saved (payment step)
    const [payBusy, setPayBusy] = useState(false);
    const [payError, setPayError] = useState('');
    const [payLater, setPayLater] = useState(false);
    const [uploads, setUploads] = useState(chat.uploads);
    const [editIdx, setEditIdx] = useState(null);
    const [typing, setTyping] = useState(true);
    // Reworded questions, kept in memory only (never stored). Missing means: show the scripted text.
    const [worded, setWorded] = useState({});
    const [messageTimes, setMessageTimes] = useState(draft?.messageTimes ?? {});
    const [intakeCursors, setIntakeCursors] = useState(draft?.intakeCursors ?? {});
    const [error, setError] = useState('');
    const [serverErrors, setServerErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);
    const logRef = useRef(null);

    const [agentText, setAgentText] = useState('');

    // When the Hermes agent runs this chat. If it cannot be reached the fixed questions carry on, keeping what it collected.
    const firstOpen = (a) => firstUnansweredIndex(questions, a);
    const resumeCollected = (collected) => {
        const merged = { ...answers, ...collected };
        setAnswers(merged);
        const next = firstOpen(merged);
        setIdx(next);
        setPhase(next >= questions.length ? 'review' : 'chat');
    };
    const agentChat = useAgentChat({
        enabled: shouldStartAgent(agent, { saved: !!saved, draft }),
        startUrl: `/invite/${token}/agent-chat/start`,
        storageKey: `wedworks.website.agent.${token.slice(0, 16)}`,
        onReady: resumeCollected,
        onFallback: resumeCollected,
    });

    const q = editIdx !== null ? questions[editIdx] : phase === 'chat' && !agentChat.active ? questions[idx] : null;

    useEffect(() => {
        if (phase !== 'saved' && !agentChat.active) {
            try { sessionStorage.setItem(draftKey, JSON.stringify({ answers, idx, phase, messageTimes, intakeCursors, currentKey: phase === 'chat' ? questions[idx]?.key : null })); } catch { /* ignore */ }
        }
    }, [answers, idx, phase, messageTimes, intakeCursors, draftKey, agentChat.active]);

    useEffect(() => {
        if (!q) { setTyping(false); return undefined; }
        setTyping(true);
        setError('');

        // Show the typing indicator for at least 480 ms, and while a reworded question is on its way.
        let cancelled = false;
        const controller = new AbortController();
        const minimum = new Promise((r) => setTimeout(r, 480));
        const wording = ai.enabled && !worded[q.key]
            ? fetchWording(`/invite/${token}/ai/reword`, { key: q.key }, controller.signal)
            : Promise.resolve(null);

        Promise.all([minimum, wording]).then(([, text]) => {
            if (cancelled) return;
            if (text) setWorded((w) => ({ ...w, [q.key]: text }));
            setMessageTimes((times) => (times[q.key]?.asked ? times : { ...times, [q.key]: { ...times[q.key], asked: new Date().toISOString() } }));
            setTyping(false);
        });

        return () => { cancelled = true; controller.abort(); };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [q?.key, phase, editIdx]);

    useEffect(() => {
        const el = logRef.current;
        if (el) el.scrollTo({ top: el.scrollHeight, behavior: 'smooth' });
    }, [idx, typing, phase, editIdx, serverErrors, uploads.length]);

    function commit(patch) {
        setIntakeCursors((cursors) => ({ ...cursors, [q.key]: null }));
        const merged = { ...answers, ...patch };
        setAnswers(merged);
        setMessageTimes((times) => ({ ...times, [q.key]: { ...times[q.key], answered: new Date().toISOString() } }));
        setServerErrors({});
        if (editIdx !== null) {
            setEditIdx(null);
            setPhase('review');
        } else {
            const next = firstOpen(merged);
            setIdx(next);
            setPhase(next >= questions.length ? 'review' : 'chat');
        }
    }

    function submitText(raw) {
        const v = raw.trim();
        if (q.key === 'description' && v.length < 5) {
            setError('A short description helps us build your website — please add a little more detail.');
            return;
        }
        if (!q.optional && v === '') {
            setError('Please answer this question.');
            return;
        }
        if (q.type === 'number' && v !== '' && !/^[1-9]\d{0,5}$/.test(v)) {
            setError('Please enter a whole number, like 150.');
            return;
        }
        commit({ [q.fields[0]]: v });
    }

    function confirm() {
        setSubmitting(true);
        const normalized = questions.reduce((a, question) => intakeFields(question).length ? { ...a, ...normalizeIntakePatch(question, a) } : a, answers);
        router.post(`/invite/${token}/confirm`, normalized, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                try { sessionStorage.removeItem(draftKey); } catch { /* ignore */ }
                agentChat.complete();
                setServerErrors({});
                setPhase('saved');
            },
            onError: (errors) => setServerErrors(errors),
            onFinish: () => setSubmitting(false),
        });
    }

    async function pay() {
        setPayBusy(true);
        setPayError('');
        const problem = await startPayment(token);
        if (problem) {
            setPayError(problem.message);
            setPayBusy(false);
            if (problem.reload) router.reload();
        }
    }

    const history = editIdx !== null || agentChat.active ? [] : questions.slice(0, phase === 'chat' ? idx : questions.length);
    const areaStart = answers.service_areas ?? (defaultArea ? [defaultArea] : []);

    function renderInput() {
        if (!q || typing) return null;
        if (intakeFields(q).length) return <IntakeInput key={q.key + editIdx} q={q} answers={answers} currentFieldKey={editIdx !== null ? null : intakeCursors[q.key]} registrationContacts={registrationContacts} onDraft={(patch, key) => { setAnswers((a) => ({ ...a, ...patch })); setIntakeCursors((cursors) => ({ ...cursors, [q.key]: key })); }} onSubmit={commit} renderUploads={({ onSubmit }) => <MediaInput q={{ optional: false }} uploadsOnly uploads={uploads} limits={limits} token={token} setError={setError} onUploaded={(u) => setUploads((x) => [...x, u])} onRemoved={(id) => setUploads((x) => x.filter((u) => u.id !== id))} onSubmit={onSubmit} />} />;
        switch (q.type) {
            case 'textarea':
            case 'text':
            case 'number':
                return q.type === 'number' || q.type === 'text' ? (
                    <div className="grid gap-s-3">
                        <InputBar
                            key={q.key}
                            value={answers[q.fields[0]] ?? ''}
                            onChange={(v) => { setAnswers((a) => ({ ...a, [q.fields[0]]: v })); setError(''); }}
                            onSubmit={() => submitText(answers[q.fields[0]] ?? '')}
                            placeholder={q.placeholder}
                            type={q.type === 'number' ? 'number' : 'text'}
                            inputMode={q.type === 'number' ? 'numeric' : undefined}
                            label={q.label}
                        />
                        {q.optional && <QuickReplyRow><QuickReply tone="no" onClick={() => commit({ [q.fields[0]]: '' })}>Skip</QuickReply></QuickReplyRow>}
                    </div>
                ) : (
                    <TextareaInput key={q.key + editIdx} q={q} initial={answers[q.fields[0]]} onSubmit={submitText} error={error} />
                );
            case 'choice':
                return (
                    <ChipRow>
                        {q.options.map((o) => (
                            <Chip key={o.value} selected={answers[q.fields[0]] === o.value} onClick={() => commit({ [q.fields[0]]: o.value })}>
                                {o.label}
                            </Chip>
                        ))}
                    </ChipRow>
                );
            case 'services':
                return <ServicesInput key={q.key + editIdx} q={q} initial={answers.services} onSubmit={(rows) => commit({ services: rows.filter((r) => r.name.trim()).map((r) => ({ name: r.name.trim(), price: r.price.trim() })) })} />;
            case 'areas':
                return <AreasInput key={q.key + editIdx} q={q} initialAreas={areaStart} initialBooking={answers.booking} onSubmit={commit} />;
            case 'media':
                return (
                    <MediaInput
                        key={q.key + editIdx}
                        q={q}
                        initialColors={answers.colors}
                        uploads={uploads}
                        limits={limits}
                        token={token}
                        error={error}
                        setError={setError}
                        onUploaded={(u) => setUploads((x) => [...x, u])}
                        onRemoved={(id) => setUploads((x) => x.filter((u) => u.id !== id))}
                        onSubmit={(colors) => commit({ colors: colors.trim() })}
                    />
                );
            default:
                return null;
        }
    }

    return (
        <Shell>
            <Header title="WedWorks Assistant" />
            <Log logRef={logRef}>
                {!!agent?.enabled && !saved && !agentChat.active && phase === 'chat' && (
                    <div><Button variant="outline" onClick={() => agentChat.retry()}>Try AI chat</Button></div>
                )}
                <AiBubble>
                    Welcome back, {firstName}! We&apos;re excited to help create a website for {businessName}. I&apos;ll ask a few
                    questions about your services and the kind of website you&apos;d like — your answers will help us prepare a website that represents your business.
                </AiBubble>

                <Card>
                    <div><Pill tone="info">Invited · slot {slot.number} of {slot.total}</Pill></div>
                    <CardTitle>You&apos;re in. Complete payment in:</CardTitle>
                    <Countdown expiresAt={expiresAt} serverNow={serverNow} onDone={() => router.reload()} />
                    <SlotMeter taken={slot.number} total={slot.total} />
                    <Note icon="clock">If payment is not completed before the timer ends, your invitation expires and the slot closes.</Note>
                </Card>

                {history.map((h) => (
                    <div key={h.key} className="contents">
                        <AiBubble at={messageTimes[h.key]?.asked}>{worded[h.key] ?? h.text}</AiBubble>
                        <UserBubble at={messageTimes[h.key]?.answered}>{summarize(h, answers, uploads) || '(skipped)'}</UserBubble>
                    </div>
                ))}

                {agentChat.active && phase === 'chat' && (
                    <>
                        {agentChat.messages.map((m) => (m.role === 'hermes' ? <AiBubble key={m.id} at={m.at}>{m.text}</AiBubble> : <UserBubble key={m.id} at={m.at}>{m.text}</UserBubble>))}
                        {agentChat.waiting && <Typing />}
                    </>
                )}

                {q && (typing ? <Typing /> : <AiBubble at={messageTimes[q.key]?.asked}>{worded[q.key] ?? q.text}</AiBubble>)}

                {phase === 'review' && editIdx === null && (
                    <Card>
                        <CardTitle>Review your website information</CardTitle>
                        <CardText>Here&apos;s everything we&apos;ll use to prepare your website. You can edit anything before continuing.</CardText>
                        {Object.keys(serverErrors).length > 0 && (
                            <Alert title="Please fix this first">{Object.values(serverErrors).join(' ')}</Alert>
                        )}
                        <ReviewRows>
                            {questions.map((r, i) => (
                                <ReviewRow key={r.key} label={r.label} value={summarize(r, answers, uploads) || '—'} onEdit={() => { setServerErrors({}); setEditIdx(i); }} />
                            ))}
                        </ReviewRows>
                        <Actions>
                            <Button block disabled={submitting} onClick={confirm}>{submitting ? 'Saving…' : 'Confirm and continue →'}</Button>
                        </Actions>
                    </Card>
                )}

                {phase === 'saved' && (payLater ? (
                    <SavedLaterCard busy={payBusy} onResume={() => setPayLater(false)} />
                ) : payment.lastFailed ? (
                    <FailedCard busy={payBusy} error={payError} onRetry={pay} />
                ) : (
                    <PaymentCard
                        businessName={businessName}
                        price={payment.price}
                        pending={false}
                        expiresAt={expiresAt}
                        serverNow={serverNow}
                        busy={payBusy}
                        error={payError}
                        onPay={pay}
                        onLater={() => setPayLater(true)}
                    />
                ))}

                {phase === 'saved' && (
                    <Actions>
                        <Button variant="outline" block onClick={() => setPhase('review')}>Review or change my answers</Button>
                    </Actions>
                )}
            </Log>

            {q && !typing && (
                <InputZone>
                    <div className="max-h-[50dvh] overflow-y-auto px-[2px] pb-[2px]">{renderInput()}</div>
                    <FieldError>{error}</FieldError>
                </InputZone>
            )}

            {agentChat.active && phase === 'chat' && (
                <InputZone>
                    {agentChat.widget === 'upload' ? (
                        <div className="max-h-[50dvh] overflow-y-auto px-[2px] pb-[2px]">
                            <MediaInput
                                q={questions.find((x) => x.type === 'media') ?? { optional: true, placeholder: 'Favorite colors, e.g. Blush pink and gold' }}
                                initialColors=""
                                uploads={uploads}
                                limits={limits}
                                token={token}
                                error={error}
                                setError={setError}
                                onUploaded={(u) => setUploads((x) => [...x, u])}
                                onRemoved={(id) => setUploads((x) => x.filter((u) => u.id !== id))}
                                onSubmit={(colors) => agentChat.send(`I'm done adding my photos. Uploaded photo IDs: ${JSON.stringify(uploads.filter((u) => u.kind === 'photo').map((u) => u.id))}. Image usage permission still needs my explicit answer.${colors.trim() ? ` My favorite colors: ${colors.trim()}.` : ''}`)}
                            />
                            <FieldError>{error}</FieldError>
                        </div>
                    ) : (
                        <InputBar
                            value={agentText}
                            disabled={agentChat.sending}
                            onChange={setAgentText}
                            onSubmit={async () => { const t = agentText; if (t.trim() && await agentChat.send(t)) setAgentText(''); }}
                            placeholder="Type your message"
                            label="Your message"
                        />
                    )}
                </InputZone>
            )}
        </Shell>
    );
}
