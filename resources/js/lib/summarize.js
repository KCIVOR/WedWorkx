/** The text shown in the chat bubble and the review row for one question. */
export function summarize(q, a, uploads) {
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
