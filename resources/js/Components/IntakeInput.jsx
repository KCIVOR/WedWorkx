import { useEffect, useState } from 'react';
import { Button, QuickReply, FieldError } from './wb';
import { TextInput, Textarea } from './wb/forms';
import { emptyIntakeValue, intakeIssue, intakeStartIndex, normalizeIntakePatch, reusableContactValue, visibleIntakeFields } from '../lib/websiteIntake';

export default function IntakeInput({ q, answers, currentFieldKey, registrationContacts, onSubmit, onDraft, renderUploads }) {
    const [patch, setPatch] = useState({});
    const [position, setPosition] = useState(() => intakeStartIndex(q, answers, currentFieldKey));
    const [error, setError] = useState('');
    const merged = { ...answers, ...patch };
    const fields = visibleIntakeFields(q, merged);
    const field = fields[Math.min(position, fields.length - 1)];
    useEffect(() => { if (Object.keys(patch).length) onDraft?.(patch, field?.key); }, [patch, field?.key]);
    if (!field) return null;
    const value = merged[field.key];
    const update = (v) => { setPatch((p) => ({ ...p, [field.key]: v })); setError(''); };
    function next(v) {
        const nextAnswers = { ...merged, [field.key]: v };
        const issue = intakeIssue(field, v, nextAnswers);
        if (issue) { setError(issue); return; }
        const nextPatch = { ...patch, [field.key]: v };
        setPatch(nextPatch);
        const visible = visibleIntakeFields(q, nextAnswers);
        const index = visible.findIndex((f) => f.key === field.key);
        if (index < visible.length - 1) { setPosition(index + 1); setError(''); return; }
        const missing = visible.find((f) => nextAnswers[f.key] === undefined || intakeIssue(f, nextAnswers[f.key], nextAnswers));
        if (missing) { setPosition(visible.indexOf(missing)); setError('Please complete this answer.'); return; }
        onSubmit(normalizeIntakePatch(q, nextAnswers));
    }
    const reusable = merged.public_contact_mode === 'reuse' ? reusableContactValue(field.key, registrationContacts) : '';
    const text = typeof value === 'string' ? value : '';
    return <div className="grid gap-s-3" key={field.key}>
        <p className="font-medium">{field.prompt || field.label}</p>
        {field.key === 'public_contact_permission' && <div className="grid gap-s-1 text-sm">{['public_email', 'public_phone', 'public_socials', 'public_address'].filter((key) => typeof merged[key] === 'string' && merged[key].trim() && (key !== 'public_address' || merged.public_address_mode === 'full')).map((key) => <p key={key}>{q.intake.fields.find((f) => f.key === key)?.label}: {merged[key]}</p>)}{merged.public_address_mode === 'area' && <p>Address: service area only</p>}</div>}
        {reusable && <div className="grid gap-s-2"><p>Registration detail: {reusable}</p><Button variant="outline" onClick={() => update(reusable)}>Use this detail</Button><p className="text-sm">Only copy this if it is a public business contact. Publication permission is confirmed separately.</p></div>}
        {field.type === 'choice' ? <div className="flex flex-wrap gap-s-2">{field.options.map((o) => <QuickReply key={o.value} onClick={() => next(o.value)}>{o.label}{value === o.value ? ' ✓' : ''}</QuickReply>)}</div>
            : field.type === 'uploads' ? renderUploads({ field, onSubmit: next })
                : field.type === 'services' ? <div className="grid gap-s-2">{(Array.isArray(value) && value.length ? value : [{ name: '', price: '' }]).map((row, i, rows) => <div className="grid grid-cols-[minmax(0,1fr)_90px_44px] gap-s-2" key={i}><TextInput aria-label={`Service ${i + 1}`} placeholder="Service/package name" value={row.name} onChange={(e) => update(rows.map((r, j) => i === j ? { ...r, name: e.target.value } : r))} /><TextInput aria-label={`Price ${i + 1}`} placeholder="Price (optional)" value={row.price} onChange={(e) => update(rows.map((r, j) => i === j ? { ...r, price: e.target.value } : r))} /><button type="button" aria-label={`Remove service ${i + 1}`} className="h-[44px] w-[44px] rounded-full text-wb-muted" onClick={() => update(rows.filter((_, j) => i !== j))}>×</button></div>)}<Button variant="outline" onClick={() => update([...(Array.isArray(value) && value.length ? value : [{ name: '', price: '' }]), { name: '', price: '' }])}>Add service</Button></div>
                    : field.type === 'areas' ? <Textarea aria-label={field.label} placeholder="One area per line" value={Array.isArray(value) ? value.join('\n') : ''} onChange={(e) => update(e.target.value.split('\n'))} />
                        : field.type === 'textarea' ? <Textarea aria-label={field.label} maxLength={field.max} value={text} onChange={(e) => update(e.target.value)} />
                            : <TextInput aria-label={field.label} type={field.type === 'email' || field.type === 'url' ? field.type : 'text'} maxLength={field.max} value={text} onChange={(e) => update(e.target.value)} />}
        <FieldError>{error}</FieldError>
        <div className="flex flex-wrap gap-s-2">
            {position > 0 && <Button variant="outline" onClick={() => { setPosition(position - 1); setError(''); }}>Back</Button>}
            {!['choice', 'uploads'].includes(field.type) && <Button onClick={() => next(field.type === 'areas' ? (value ?? []).map((s) => s.trim()).filter(Boolean) : typeof value === 'string' ? value.trim() : value ?? emptyIntakeValue(field))}>Continue</Button>}
            {!field.required && field.type !== 'uploads' && <QuickReply onClick={() => next(emptyIntakeValue(field))}>Skip</QuickReply>}
        </div>
    </div>;
}
