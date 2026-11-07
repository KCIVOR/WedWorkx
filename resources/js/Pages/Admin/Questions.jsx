import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Alert, Button, Note, Pill } from '../../Components/wb';
import { TextInput, Textarea } from '../../Components/wb/forms';
import { AdminLayout, Modal, Panel, Select, Table, Td, Th } from '../../Components/admin/ui';

const TYPE_LABEL = {
    core: 'Required detail',
    text: 'Short answer',
    textarea: 'Long answer',
    number: 'Number',
    choice: 'Choice',
    services: 'Services list',
    areas: 'Areas and booking',
    media: 'Photos, logo, colors',
};

/** The two chatbots, with what each one is for and where its questions are saved. */
const BOTS = {
    registration: {
        title: 'Registration chat',
        step: 'Bot 1',
        when: 'Asked first, when someone scans the QR code and registers.',
        keeps: 'The 7 core questions fill the supplier\'s own details (name, email, mobile, business, type, location, social link), so they can be reworded but not removed. Extra questions you add are saved with the supplier and shown on their page and to Hermes.',
        path: '/questions/registration',
        token: '{name}',
        tokenHelp: 'You can write {name} and it is replaced with the supplier\'s name once they have given it.',
        lockedPill: 'Needed to register',
        lockedNote: 'This question is needed to register, so only its wording can be changed.',
        hasScope: false,
        newDefaults: { type: 'text', required: false },
    },
    website: {
        title: 'Website chat',
        step: 'Bot 2',
        when: 'Asked after a supplier is invited, before they pay, to build their website.',
        keeps: 'Five questions are needed to build the site (description, services, areas, photos and colors, style): their wording can change, they cannot be removed. Suppliers who already confirmed keep the wording they saw.',
        path: '/questions',
        token: '{business_name}',
        tokenHelp: 'You can write {business_name} and it is replaced with the supplier\'s business name.',
        lockedPill: 'Needed for the site',
        lockedNote: 'This question is needed to build the website, so only its wording can be changed.',
        hasScope: true,
        newDefaults: { type: 'textarea', required: false },
    },
};

/** A label, the input, an optional hint and an optional error. */
function Row({ label, help, error, children }) {
    return (
        <div className="grid min-w-0 gap-[6px]">
            <label className="text-[13px] font-semibold text-wb-heading">{label}</label>
            {children}
            {help && <div className="text-[12.5px] text-wb-muted">{help}</div>}
            {error && <div role="alert" className="text-[12.5px] text-red-600">{error}</div>}
        </div>
    );
}

function QuestionForm({ bot, question, types, creatable, onDone }) {
    const cfg = BOTS[bot];
    const base = `/${usePage().props.admin.path}${cfg.path}`;
    const isNew = !question;
    const locked = !!question?.locked;
    const used = question?.used_count ?? 0;
    const form = useForm(isNew ? {
        label: '', text: '', type: cfg.newDefaults.type, placeholder: '', required: cfg.newDefaults.required,
        scope: 'all', types: [], options: [{ value: null, label: '' }, { value: null, label: '' }],
    } : {
        label: question.label,
        text: question.text,
        type: question.type,
        placeholder: question.placeholder ?? '',
        required: question.required,
        scope: question.applies_to ? 'types' : 'all',
        types: question.applies_to ?? [],
        options: question.options ?? [],
    });
    const { data, setData, errors } = form;
    const isChoice = data.type === 'choice';
    const typeFrozen = locked || (!isNew && used > 0);
    const typeChoices = locked ? [data.type] : creatable;

    const submit = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onDone };
        if (isNew) form.post(base, opts); else form.put(`${base}/${question.id}`, opts);
    };
    const setOption = (i, label) => setData('options', data.options.map((o, j) => (j === i ? { ...o, label } : o)));
    const toggleType = (t) => setData('types', data.types.includes(t) ? data.types.filter((x) => x !== t) : [...data.types, t]);

    return (
        <Modal title={isNew ? `Add a question to the ${cfg.title.toLowerCase()}` : `Edit: ${question.label}`} onClose={onDone}>
            <form onSubmit={submit} className="grid gap-s-4">
                {locked && <Note>{cfg.lockedNote}</Note>}
                {errors.question && <Alert tone="danger">{errors.question}</Alert>}

                <Row label="Name (shown in the review list and to Hermes)" error={errors.label}>
                    <TextInput value={data.label} maxLength={80} invalid={!!errors.label} onChange={(e) => setData('label', e.target.value)} />
                </Row>
                <Row label="Question the supplier sees" error={errors.text} help={cfg.tokenHelp}>
                    <Textarea value={data.text} maxLength={500} invalid={!!errors.text} onChange={(e) => setData('text', e.target.value)} />
                </Row>

                <div className="grid gap-s-4 md:grid-cols-2">
                    <Row label="Type of answer" error={errors.type}
                        help={typeFrozen && !locked ? `${used} ${used === 1 ? 'supplier has' : 'suppliers have'} answered this, so the type is fixed.` : null}>
                        <Select value={data.type} disabled={typeFrozen} onChange={(e) => setData('type', e.target.value)}>
                            {typeChoices.map((t) => <option key={t} value={t}>{TYPE_LABEL[t]}</option>)}
                        </Select>
                    </Row>
                    {!isChoice && question?.key !== 'business_type' && (
                        <Row label="Hint inside the box (optional)" error={errors.placeholder}>
                            <TextInput value={data.placeholder} maxLength={160} onChange={(e) => setData('placeholder', e.target.value)} />
                        </Row>
                    )}
                </div>

                {isChoice && (
                    <Row label="Options" error={errors.options}
                        help={locked ? 'You can reword the options. Their saved values stay the same.' : 'At least two. Suppliers who chose an option keep it, so options they chose cannot be removed.'}>
                        <div className="grid gap-s-2">
                            {data.options.map((o, i) => (
                                <div key={i} className="flex gap-s-2">
                                    <TextInput value={o.label} maxLength={80} aria-label={`Option ${i + 1}`} onChange={(e) => setOption(i, e.target.value)} />
                                    {!locked && (
                                        <Button variant="outline" aria-label={`Remove option ${i + 1}`} onClick={() => setData('options', data.options.filter((_, j) => j !== i))}>Remove</Button>
                                    )}
                                </div>
                            ))}
                            {!locked && data.options.length < 12 && (
                                <div><Button variant="ghost" onClick={() => setData('options', [...data.options, { value: null, label: '' }])}>+ Add option</Button></div>
                            )}
                        </div>
                    </Row>
                )}

                {!locked && (
                    <label className="flex items-center gap-s-2 text-[14px] text-wb-heading">
                        <input type="checkbox" className="h-[18px] w-[18px] accent-wb-accent" checked={data.required} onChange={(e) => setData('required', e.target.checked)} />
                        The supplier must answer this (no Skip)
                    </label>
                )}

                {!locked && cfg.hasScope && (
                    <Row label="Who is asked" error={errors.types || errors.applies_to}>
                        <Select value={data.scope} onChange={(e) => setData('scope', e.target.value)}>
                            <option value="all">Every business type</option>
                            <option value="types">Only some business types</option>
                        </Select>
                        {data.scope === 'types' && (
                            <div className="mt-s-2 grid gap-s-2 sm:grid-cols-2">
                                {Object.entries(types).map(([key, name]) => (
                                    <label key={key} className="flex items-center gap-s-2 text-[13.5px] text-wb-text">
                                        <input type="checkbox" className="h-[16px] w-[16px] accent-wb-accent" checked={data.types.includes(key)} onChange={() => toggleType(key)} />
                                        {name}
                                    </label>
                                ))}
                            </div>
                        )}
                    </Row>
                )}

                <div className="sticky bottom-0 -mx-[18px] flex flex-wrap gap-s-2 border-t border-wb-line bg-white px-[18px] py-s-3">
                    <Button type="submit" disabled={form.processing}>{isNew ? 'Add question' : 'Save changes'}</Button>
                    <Button variant="outline" onClick={onDone}>Cancel</Button>
                </div>
            </form>
        </Modal>
    );
}

/** One chatbot's questions: the form, the ordered list and the deleted ones. */
function Manager({ bot, items, deleted, creatable, max, types }) {
    const cfg = BOTS[bot];
    const { admin, errors } = usePage().props;
    const base = `/${admin.path}${cfg.path}`;
    const [editing, setEditing] = useState(null); // null | 'new' | question id
    const [preview, setPreview] = useState('');

    const visible = cfg.hasScope ? items.filter((q) => !preview || !q.applies_to || q.applies_to.includes(preview)) : items;
    const ids = items.map((q) => q.id);

    const move = (id, dir) => {
        const pos = visible.findIndex((q) => q.id === id);
        const other = visible[pos + dir];
        if (!other) return;
        const order = [...ids];
        const a = order.indexOf(id);
        const b = order.indexOf(other.id);
        [order[a], order[b]] = [order[b], order[a]];
        router.post(`${base}/reorder`, { order }, { preserveScroll: true });
    };
    const remove = (q) => {
        const warn = q.used_count ? ` ${q.used_count} ${q.used_count === 1 ? 'supplier has' : 'suppliers have'} already answered it; their answers are kept.` : '';
        if (window.confirm(`Delete "${q.label}"?${warn} You can restore it later.`)) router.delete(`${base}/${q.id}`, { preserveScroll: true });
    };
    const editingQuestion = typeof editing === 'number' ? items.find((q) => q.id === editing) : null;
    const appliesLabel = (q) => (q.applies_to ? (q.applies_to.length === 1 ? types[q.applies_to[0]] : `${q.applies_to.length} types`) : 'All types');
    const fill = (text) => text.replace(cfg.token, preview ? 'Your Business' : cfg.token);
    const btn = 'min-h-[36px] rounded-r-pill border border-slate-300 bg-white px-s-3 text-[13px] font-semibold hover:border-wb-accent disabled:opacity-40';

    return (
        <>
            {editing === null && (errors.question || errors.order) && <Alert tone="danger">{errors.question || errors.order}</Alert>}

            {editing !== null && (
                <QuestionForm key={`${bot}-${editing}`} bot={bot} question={editingQuestion} types={types} creatable={creatable} onDone={() => setEditing(null)} />
            )}

            <Panel
                title={cfg.hasScope ? `${cfg.title} questions (${items.length} in total, up to ${max} for each business type)` : `${cfg.title} questions (${items.length} of ${max})`}
                action={(
                    <div className="flex flex-wrap items-center gap-s-2">
                        {cfg.hasScope && (
                            <div className="w-[240px] max-w-full">
                                <Select value={preview} aria-label="Preview as" onChange={(e) => setPreview(e.target.value)}>
                                    <option value="">Show all questions</option>
                                    {Object.entries(types).map(([key, name]) => <option key={key} value={key}>Preview: {name}</option>)}
                                </Select>
                            </div>
                        )}
                        <Button onClick={() => setEditing('new')}>Add a question</Button>
                    </div>
                )}
            >
                {preview && (
                    <p className="mb-s-3 text-[13px] text-wb-muted">
                        A {types[preview]} sees these {visible.length} questions in this order, with "Your Business" in place of {cfg.token}.
                    </p>
                )}
                <Table>
                    <thead>
                        <tr>
                            <Th>#</Th><Th>Question</Th><Th>Answer type</Th>
                            {cfg.hasScope && <Th>Asked to</Th>}
                            <Th>Answer</Th><Th>Answered by</Th><Th>Actions</Th>
                        </tr>
                    </thead>
                    <tbody>
                        {visible.map((q, i) => (
                            <tr key={q.id}>
                                <Td className="tabular-nums text-wb-muted">{i + 1}</Td>
                                <Td className="max-w-[420px]">
                                    <div className="font-semibold text-wb-heading">{q.label}</div>
                                    <div className="mt-[2px] whitespace-pre-line text-[13px] text-wb-muted">{fill(q.text)}</div>
                                </Td>
                                <Td>
                                    <Pill tone="info">{TYPE_LABEL[q.type]}</Pill>
                                    {q.locked && <div className="mt-s-1"><Pill tone="muted">{cfg.lockedPill}</Pill></div>}
                                </Td>
                                {cfg.hasScope && <Td>{appliesLabel(q)}</Td>}
                                <Td><Pill tone={q.required ? 'warning' : 'muted'}>{q.required ? 'Required' : 'Optional'}</Pill></Td>
                                <Td className="tabular-nums">{bot === 'registration' && q.locked ? 'Everyone' : q.used_count}</Td>
                                <Td>
                                    <div className="flex flex-wrap gap-s-1">
                                        <button type="button" aria-label={`Move ${q.label} up`} disabled={i === 0} onClick={() => move(q.id, -1)} className={`${btn} text-wb-heading`}>Up</button>
                                        <button type="button" aria-label={`Move ${q.label} down`} disabled={i === visible.length - 1} onClick={() => move(q.id, 1)} className={`${btn} text-wb-heading`}>Down</button>
                                        <button type="button" onClick={() => setEditing(q.id)} className={`${btn} text-wb-accent`}>Edit</button>
                                        {!q.locked && <button type="button" onClick={() => remove(q)} className={`${btn} text-red-600`}>Delete</button>}
                                    </div>
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
            </Panel>

            {deleted.length > 0 && (
                <Panel title="Deleted questions">
                    <div className="grid gap-s-2">
                        {deleted.map((q) => (
                            <div key={q.id} className="flex flex-wrap items-center justify-between gap-s-2 border-b border-wb-line pb-s-2 last:border-b-0">
                                <span className="text-[14px] text-wb-heading">{q.label} <span className="text-wb-muted">({TYPE_LABEL[q.type]})</span></span>
                                <Button variant="outline" onClick={() => router.post(`${base}/${q.id}/restore`, {}, { preserveScroll: true })}>Restore</Button>
                            </div>
                        ))}
                    </div>
                </Panel>
            )}
        </>
    );
}

export default function Questions({ registration, questions, deleted, types, creatable, max }) {
    const initial = typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('bot') === 'website' ? 'website' : 'registration';
    const [tab, setTab] = useState(initial);

    const pick = (next) => {
        setTab(next);
        try { window.history.replaceState(null, '', `${window.location.pathname}?bot=${next}`); } catch { /* the tab still switches */ }
    };

    // The website total counts every business type's own questions; one supplier is asked fewer.
    const counts = { registration: `${registration.questions.length} questions`, website: `${questions.length} in total` };

    return (
        <AdminLayout title="Questions" current="/questions">
            <Head title="Questions" />

            <div role="tablist" aria-label="Chatbot" className="grid gap-s-3 md:grid-cols-2">
                {Object.entries(BOTS).map(([key, b]) => {
                    const active = tab === key;
                    return (
                        <button
                            key={key}
                            type="button"
                            role="tab"
                            aria-selected={active}
                            onClick={() => pick(key)}
                            className={`min-w-0 rounded-r-card border p-[18px] text-left transition duration-fast ${active ? 'border-wb-accent bg-white shadow-sh-sm ring-2 ring-wb-accent' : 'border-wb-line bg-white hover:border-wb-accent'}`}
                        >
                            <div className="flex flex-wrap items-center justify-between gap-s-2">
                                <span className="text-[11px] font-semibold uppercase tracking-[.08em] text-wb-muted">{b.step}</span>
                                <Pill tone={active ? 'info' : 'muted'}>{counts[key]}</Pill>
                            </div>
                            <div className="mt-s-1 font-display text-[17px] font-bold tracking-[-.01em] text-wb-heading">{b.title}</div>
                            <div className="mt-[2px] text-[13px] text-wb-muted">{b.when}</div>
                        </button>
                    );
                })}
            </div>

            <Note>{BOTS[tab].keeps}</Note>

            {tab === 'registration' ? (
                <Manager key="registration" bot="registration" items={registration.questions} deleted={registration.deleted} creatable={registration.creatable} max={registration.max} types={types} />
            ) : (
                <Manager key="website" bot="website" items={questions} deleted={deleted} creatable={creatable} max={max} types={types} />
            )}
        </AdminLayout>
    );
}
