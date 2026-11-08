import { intakeFields, visibleIntakeFields } from './websiteIntake.js';
/** The text shown in the chat bubble and the review row for one question. */
export function summarize(q, a, uploads = []) {
    if (intakeFields(q).length) return visibleIntakeFields(q, a).map((f) => {
        const v = a[f.key];
        if (v == null || v === '' || (Array.isArray(v) && !v.length)) return `${f.label}: (skipped)`;
        let display;
        if (f.type === 'choice') display = f.options?.find((o) => o.value === v)?.label ?? '';
        else if (f.type === 'services') display = Array.isArray(v) ? v.map((r) => `${r.name ?? ''}${r.price ? ` (${r.price})` : ''}${r.details ? `: ${r.details}` : ''}`).join(', ') : '';
        else if (f.type === 'uploads') display = Array.isArray(v) ? v.map((id) => uploads.find((u) => u.id === id)?.original_name || `Photo #${id}`).join(', ') : '';
        else display = Array.isArray(v) ? v.filter((x) => typeof x === 'string').join(', ') : typeof v === 'string' ? v : '';
        return `${f.label}: ${display}`;
    }).join(' · ');
    switch (q.type) {
        case 'services': {
            const items = a.services ?? [];
            return items.length ? items.map((s) => s.name + (s.price ? ` (${s.price})` : '')).join(', ') : '';
        }
        case 'areas': {
            const areas = a.service_areas ?? [];
            const parts = [];
            if (areas.length) parts.push(`Areas: ${areas.join(', ')}`);
            if (a.booking) parts.push(`Booking: ${a.booking}`);
            return parts.join(' · ');
        }
        case 'media': {
            const parts = [];
            const logo = uploads.filter((u) => u.kind === 'logo').length;
            const photos = uploads.filter((u) => u.kind === 'photo').length;
            if (logo) parts.push('1 logo');
            if (photos) parts.push(`${photos} photo${photos > 1 ? 's' : ''}`);
            if (a.colors) parts.push(`Colors: ${a.colors}`);
            return parts.join(' · ');
        }
        case 'choice':
            return q.options.find((o) => o.value === a[q.fields[0]])?.label ?? '';
        default:
            return a[q.fields[0]] ?? '';
    }
}
