import { shouldStartAgent } from '../lib/agentChatRecovery';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Actions, AiBubble, Alert, Button, Card, CardIcon, CardText, CardTitle, Chip, ChipRow,
    FieldError, Header, InputBar, InputZone, Log, Note, QuickReply, QuickReplyRow,
    ReviewRow, ReviewRows, Shell, Typing, UserBubble,
} from '../Components/wb';
import { fetchWording } from '../lib/wording';
import { useAgentChat } from '../lib/agentChat';
import { firstUnansweredIndex, resumeQuestionIndex } from '../lib/questionSequence';

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const normalizeMobile = (v) => {
    const s = v.replace(/[\s\-().]/g, '');
    let m = s.match(/^09(\d{9})$/) || s.match(/^\+639(\d{9})$/);
    return m ? `+639${m[1]}` : null;
};
const normalizeLink = (v) => (v && !/^[a-z][a-z0-9+.-]*:\/\//i.test(v) ? `https://${v}` : v);
const isUrl = (v) => {
    try {
        const u = new URL(v);
        return (u.protocol === 'http:' || u.protocol === 'https:') && u.hostname.includes('.');
    } catch {
        return false;
    }
};

/* The 7 core questions keep their input rules here (they fill the supplier's own fields). Their wording, label and hint
   come from the server and are edited in Admin > Questions. Questions an admin adds use a plain rule for their type. */
const CORE = {
    name: { type: 'text', autoComplete: 'name', validate: (v) => (v.length < 2 ? 'Please enter your name.' : null) },
    email: { type: 'email', inputMode: 'email', autoComplete: 'email', validate: (v) => (!EMAIL_RE.test(v) ? 'That email looks incomplete — please double check.' : null) },
    mobile: { type: 'tel', inputMode: 'tel', autoComplete: 'tel', validate: (v) => (normalizeMobile(v) ? null : 'Please enter a valid mobile number, like 09171234567 or +639171234567.') },
    business_name: { type: 'text', validate: (v) => (v.length < 2 ? 'Please enter your business name.' : null) },
    business_type: { type: 'select' },
    location: { type: 'text', validate: (v) => (v.length < 2 ? 'Please enter your city or province.' : null) },
    social_link: { type: 'text', validate: (v) => (v && !isUrl(normalizeLink(v)) ? 'That link does not look right — please check it or skip this question.' : null) },
};

function toStep(q) {
    const base = { key: q.key, label: q.label, text: q.text, placeholder: q.placeholder ?? undefined, optional: q.optional, options: q.options };
    if (q.core) return { ...CORE[q.key], ...base };
    if (q.type === 'choice') return { ...base, type: 'select' };
    if (q.type === 'number') {
        return { ...base, type: 'number', inputMode: 'numeric', validate: (v) => (v !== '' && !/^\d{1,7}$/.test(v) ? 'Please enter a whole number, like 5.' : !q.optional && v === '' ? 'Please answer this question.' : null) };
    }
    return { ...base, type: 'text', validate: (v) => (!q.optional && v === '' ? 'Please answer this question.' : null) };
}

const DRAFT_KEY = 'wedworks.registration.draft';
const loadDraft = () => {
    try {
        const d = JSON.parse(sessionStorage.getItem(DRAFT_KEY));
        if (d && typeof d.answers === 'object' && Number.isInteger(d.idx)) return d;
    } catch { /* storage may be unavailable */ }
    return null;
};
const saveDraft = (d) => { try { sessionStorage.setItem(DRAFT_KEY, JSON.stringify(d)); } catch { /* ignore */ } };
const clearDraft = () => { try { sessionStorage.removeItem(DRAFT_KEY); } catch { /* ignore */ } };

export default function Register({ businessTypes, questions, ai, agent }) {
    const { props } = usePage();
    const STEPS = useMemo(() => questions.map(toStep), [questions]);
    const typeLabel = useMemo(() => Object.fromEntries(businessTypes.map((t) => [t.value, t.label])), [businessTypes]);

    const draft = useMemo(loadDraft, []);
    const [answers, setAnswers] = useState(draft?.answers ?? {});
    const [idx, setIdx] = useState(() => resumeQuestionIndex(STEPS, draft));
    const [editIdx, setEditIdx] = useState(null);
    const [phase, setPhase] = useState(() => agent?.resume ? 'chat' : resumeQuestionIndex(STEPS, draft) >= STEPS.length ? 'review' : 'chat'); // chat | review | done
    const [result, setResult] = useState(null);
    const [value, setValue] = useState('');
    const [error, setError] = useState('');
    const [typing, setTyping] = useState(true);
    // Reworded questions, kept in memory only (never stored). Missing means: show the scripted text.
    const [worded, setWorded] = useState({});
    const [messageTimes, setMessageTimes] = useState(draft?.messageTimes ?? {});
    const [submitting, setSubmitting] = useState(false);
    const [serverErrors, setServerErrors] = useState({});
    const logRef = useRef(null);
    const [agentText, setAgentText] = useState('');

    // When the Hermes agent runs this chat. If it cannot be reached the fixed questions carry on, keeping what it collected.
    const firstOpen = (a) => firstUnansweredIndex(STEPS, a);
    const resumeCollected = (collected) => {
        const merged = { ...answers, ...collected };
        setAnswers(merged);
        const next = firstOpen(merged);
        setIdx(next);
        setPhase(next >= STEPS.length ? 'review' : 'chat');
    };
    const agentChat = useAgentChat({
        enabled: shouldStartAgent(agent, { draft }),
        startUrl: '/agent-chat/registration/start',
        storageKey: 'wedworks.registration.agent',
        onReady: resumeCollected,
        onFallback: resumeCollected,
    });

    // What the supplier sees: the reworded question if there is one, otherwise the scripted text (same source as the server).
    const questionText = (s) => worded[s.key] ?? s.text.replace('{name}', answers.name ?? 'there');

    const step = editIdx !== null ? STEPS[editIdx] : phase === 'chat' && !agentChat.active ? STEPS[idx] : null;
    const display = (s, a) => (s.key === 'business_type' ? typeLabel[a[s.key]] : s.options ? s.options.find((o) => o.value === a[s.key])?.label : a[s.key]) || '';

    // Persist the draft in the browser session until submit.
    useEffect(() => {
        if (phase !== 'done' && !agentChat.active) saveDraft({ answers, idx, phase, messageTimes, currentKey: phase === 'chat' ? STEPS[idx]?.key : null });
    }, [answers, idx, phase, messageTimes, agentChat.active]);

    // Typing indicator before each question; prefill when editing.
    useEffect(() => {
        if (!step) { setTyping(false); return undefined; }
        setTyping(true);
        setError('');
        setValue(editIdx !== null ? (answers[step.key] ?? '') : '');

        // Show the typing indicator for at least 480 ms, and while a reworded question is on its way.
        let cancelled = false;
        const controller = new AbortController();
        const minimum = new Promise((r) => setTimeout(r, 480));
        const wording = ai.enabled && !worded[step.key]
            ? fetchWording('/ai/reword', {
                key: step.key,
                first_name: answers.name ? answers.name.split(' ')[0] : undefined,
                business_name: answers.business_name || undefined,
            }, controller.signal)
            : Promise.resolve(null);

        Promise.all([minimum, wording]).then(([, text]) => {
            if (cancelled) return;
            if (text) setWorded((w) => ({ ...w, [step.key]: text }));
            setMessageTimes((times) => (times[step.key]?.asked ? times : { ...times, [step.key]: { ...times[step.key], asked: new Date().toISOString() } }));
            setTyping(false);
        });

        return () => { cancelled = true; controller.abort(); };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [step?.key, phase, editIdx]);

    useEffect(() => {
        const el = logRef.current;
        if (el) el.scrollTo({ top: el.scrollHeight, behavior: 'smooth' });
    }, [idx, typing, phase, editIdx, result, serverErrors]);

    function commit(raw) {
        const s = step;
        const v = raw.trim();
        const err = s.validate ? s.validate(v) : null;
        if (err) { setError(err); return; }
        const stored = s.key === 'mobile' ? normalizeMobile(v) : s.key === 'social_link' ? normalizeLink(v) : v;
        const merged = { ...answers, [s.key]: stored };
        setAnswers(merged);
        setMessageTimes((times) => ({ ...times, [s.key]: { ...times[s.key], answered: new Date().toISOString() } }));
        setServerErrors({});
        if (editIdx !== null) {
            setEditIdx(null);
            setPhase('review');
        } else {
            const next = firstOpen(merged);
            setIdx(next);
            setPhase(next >= STEPS.length ? 'review' : 'chat');
        }
    }

    function submit() {
        setSubmitting(true);
        router.post('/register', answers, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: (page) => {
                const flash = page.props.flash?.registration;
                clearDraft();
                agentChat.complete();
                setServerErrors({});
                setResult(flash?.outcome ?? 'registered');
                setPhase('done');
            },
            onError: (errors) => setServerErrors(errors),
            onFinish: () => setSubmitting(false),
        });
    }

    function startEdit(i) {
        setServerErrors({});
        setEditIdx(i);
    }

    const firstName = (answers.name || '').split(' ')[0];
    const history = editIdx !== null || agentChat.active ? [] : STEPS.slice(0, phase === 'chat' ? idx : STEPS.length);

    return (
        <>
            <Head title="Register" />
            <main className="flex min-h-dvh justify-center p-0 md:items-center md:p-s-8">
                <div className="h-dvh w-full md:max-w-[600px] md:h-[calc(100dvh-64px)] md:max-h-[760px]">
                    <Shell>
                        <Header title="WedWorks Assistant" />
                        <Log logRef={logRef}>
                            {agentChat.notice && <p role="status" className="text-sm text-wb-muted">{agentChat.notice}</p>}
                            {!!agent?.enabled && !agentChat.active && phase === 'chat' && (
                                <div><Button variant="outline" onClick={() => agentChat.retry()}>Try AI chat</Button></div>
                            )}
                            {history.map((s) => (
                                <div key={s.key} className="contents">
                                    <AiBubble at={messageTimes[s.key]?.asked}>{questionText(s)}</AiBubble>
                                    <UserBubble at={messageTimes[s.key]?.answered}>{display(s, answers) || '(skipped)'}</UserBubble>
                                </div>
                            ))}

                            {agentChat.active && phase === 'chat' && (
                                <>
                                    {agentChat.messages.map((m) => (m.role === 'hermes' ? <AiBubble key={m.id} at={m.at}>{m.text}</AiBubble> : <UserBubble key={m.id} at={m.at}>{m.text}</UserBubble>))}
                                    {agentChat.waiting && <Typing />}
                                </>
                            )}

                            {step && (typing ? <Typing /> : <AiBubble at={messageTimes[step.key]?.asked}>{questionText(step)}</AiBubble>)}

                            {phase === 'review' && editIdx === null && (
                                <Card>
                                    <CardTitle>Review your information</CardTitle>
                                    <CardText>Please check your details before finishing registration. You can edit anything below.</CardText>
                                    {Object.keys(serverErrors).length > 0 && (
                                        <Alert title="Please fix this first">
                                            {Object.values(serverErrors).join(' ')}
                                        </Alert>
                                    )}
                                    <ReviewRows>
                                        {STEPS.map((s, i) => (
                                            <ReviewRow key={s.key} label={s.label} value={display(s, answers) || '—'} onEdit={() => startEdit(i)} />
                                        ))}
                                    </ReviewRows>
                                    <Actions>
                                        <Button block disabled={submitting} onClick={submit}>
                                            {submitting ? 'Submitting…' : 'Finish registration →'}
                                        </Button>
                                    </Actions>
                                </Card>
                            )}

                            {phase === 'done' && result === 'registered' && (
                                <Card>
                                    <CardIcon tone="success" name="mail" />
                                    <CardTitle>Thank you, {firstName}!</CardTitle>
                                    <CardText>
                                        We&apos;ve received your registration for <strong>{answers.business_name}</strong>. We&apos;ll send an
                                        update to <strong>{answers.email}</strong> to let you know your next step — please check your inbox.
                                    </CardText>
                                    <Note>
                                        This offer is limited to our first 10 suppliers. We&apos;ll only reach you by email, so keep an eye on
                                        your inbox over the next 24 hours.
                                    </Note>
                                </Card>
                            )}

                            {phase === 'done' && result === 'already_registered' && (
                                <Card>
                                    <CardIcon tone="warning" name="alert" />
                                    <CardTitle>You&apos;re already registered</CardTitle>
                                    <CardText>
                                        That email or mobile number is already registered for this campaign, so no new spot was used.
                                        Please check your inbox for our earlier message.
                                    </CardText>
                                    <Actions>
                                        <Button variant="outline" block onClick={() => { setPhase('review'); setResult(null); }}>
                                            Check my details
                                        </Button>
                                    </Actions>
                                </Card>
                            )}
                        </Log>

                        {step && !typing && (
                            <InputZone>
                                {step.type === 'select' ? (
                                    <ChipRow>
                                        {(step.key === 'business_type' ? businessTypes : step.options).map((t) => (
                                            <Chip key={t.value} selected={answers[step.key] === t.value} onClick={() => commit(t.value)}>
                                                {t.label}
                                            </Chip>
                                        ))}
                                    </ChipRow>
                                ) : (
                                    <>
                                        {step.optional && (
                                            <div className="mb-s-3">
                                                <QuickReplyRow>
                                                    <QuickReply tone="no" onClick={() => commit('')}>Skip</QuickReply>
                                                </QuickReplyRow>
                                            </div>
                                        )}
                                        <InputBar
                                            key={`${step.key}-${editIdx}`}
                                            value={value}
                                            onChange={(v) => { setValue(v); setError(''); }}
                                            onSubmit={() => commit(value)}
                                            placeholder={step.placeholder}
                                            type={step.type}
                                            inputMode={step.inputMode}
                                            autoComplete={step.autoComplete}
                                            label={step.label}
                                        />
                                    </>
                                )}
                                <FieldError>{error}</FieldError>
                            </InputZone>
                        )}
                        {agentChat.active && phase === 'chat' && (
                            <InputZone>
                                <InputBar
                                    value={agentText}
                            disabled={agentChat.sending}
                                    onChange={setAgentText}
                                    onSubmit={async () => { const t = agentText; if (t.trim() && await agentChat.send(t)) setAgentText(''); }}
                                    placeholder="Type your message"
                                    label="Your message"
                                />
                            </InputZone>
                        )}
                    </Shell>
                </div>
            </main>
        </>
    );
}
