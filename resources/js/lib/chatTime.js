const clock = new Intl.DateTimeFormat('en-PH', {
    timeZone: 'Asia/Manila', hour: 'numeric', minute: '2-digit', hour12: true,
});
const calendar = new Intl.DateTimeFormat('en-PH', {
    timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'short',
});

export function chatTime(value) {
    if (!value) return null;
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return null;
    return { label: clock.format(date), title: `${calendar.format(date)} (Philippine time)`, iso: date.toISOString() };
}
