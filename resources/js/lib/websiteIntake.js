export const intakeFields = (q) => q?.intake?.version === 1 ? q.intake.fields ?? [] : [];
export const emptyIntakeValue = (f) => ['services', 'areas', 'uploads'].includes(f.type) ? [] : null;
export function visibleIntakeFields(q, answers = {}) {
    const fields = intakeFields(q);
    const visible = (f, seen = new Set()) => {
        if (!f.when) return true;
        if (seen.has(f.key)) return false;
        const parent = fields.find((x) => x.key === f.when.field);
        return (!parent || visible(parent, new Set([...seen, f.key]))) && f.when.in.includes(answers[f.when.field]);
    };
    return fields.filter((f) => visible(f));
}
export function intakeIssue(f, value) {
    const empty = value == null || value === '' || (Array.isArray(value) && !value.length);
    if (empty) return f.required ? 'Please answer this question.' : null;
    if (['services', 'areas', 'uploads'].includes(f.type)) {
        if (!Array.isArray(value)) return 'Please add a valid list.';
        if (['services', 'areas'].includes(f.type) && value.length > 20) return 'Please add no more than 20 items.';
        if (f.type === 'services' && value.some((s) => !s || typeof s.name !== 'string' || !s.name.trim() || typeof s.price !== 'string' || s.name.length > 120 || s.price.length > 40)) return 'Please give each service a name and a valid price or leave its price blank.';
        if (f.type === 'areas' && value.some((s) => typeof s !== 'string' || !s.trim() || s.length > 80)) return 'Please enter valid service areas.';
        if (f.type === 'uploads' && value.some((id) => !Number.isInteger(id) || id < 1)) return 'Please upload your photos first.';
        return null;
    }
    if (typeof value !== 'string' || !value.trim()) return 'Please enter an answer.';
    if (f.key === 'description' && value.trim().length < 5) return 'Please add a little more detail about your business.';
    if (f.max && value.length > f.max) return `Please use no more than ${f.max} characters.`;
    if (f.type === 'choice' && !f.options?.some((o) => o.value === value)) return 'Please choose one of the available options.';
    if (f.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) return 'Please enter a valid email address.';
    if (f.type === 'url') { try { if (!['http:', 'https:'].includes(new URL(value).protocol)) return 'Please enter a public http or https link.'; } catch { return 'Please enter a valid public URL including https://.'; } }
    return null;
}
export function intakeComplete(q, answers = {}) {
    return visibleIntakeFields(q, answers).every((f) => Object.hasOwn(answers, f.key) && answers[f.key] !== undefined && !intakeIssue(f, answers[f.key], answers));
}
export function intakeStartIndex(q, answers = {}, currentFieldKey = null) {
    const fields = visibleIntakeFields(q, answers);
    const saved = fields.findIndex((f) => f.key === currentFieldKey);
    if (saved >= 0) return saved;
    const missing = fields.findIndex((f) => answers[f.key] === undefined || intakeIssue(f, answers[f.key]));
    return missing < 0 ? 0 : missing;
}
export function normalizeIntakePatch(q, answers = {}) {
    const visible = new Set(visibleIntakeFields(q, answers).map((f) => f.key));
    return Object.fromEntries(intakeFields(q).map((f) => [f.key, visible.has(f.key) ? answers[f.key] ?? emptyIntakeValue(f) : emptyIntakeValue(f)]));
}
export function reusableContactValue(fieldKey, contacts = {}) {
    return ({ public_email: contacts.email, public_phone: contacts.phone ?? contacts.mobile, public_socials: contacts.socials ?? contacts.social_link, public_address: contacts.location ?? [contacts.city, contacts.province].filter(Boolean).join(', ') })[fieldKey] || '';
}
