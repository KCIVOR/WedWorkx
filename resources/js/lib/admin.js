/** Small formatting helpers for the admin pages. Times show in the campaign's time zone (Manila). */
const TZ = 'Asia/Manila';

export function fmtDate(iso, withTime = true, withYear = true) {
    if (!iso) return '-';
    const opts = { timeZone: TZ, month: 'short', day: 'numeric' };
    if (withYear) opts.year = 'numeric';
    if (withTime) Object.assign(opts, { hour: 'numeric', minute: '2-digit' });
    return new Date(iso).toLocaleString('en-PH', opts);
}

export const peso = (n) => `₱${Number(n ?? 0).toLocaleString('en-PH')}`;

/** "in 1h 20m" / "12m ago" style distance, relative to the server clock. */
export function relative(iso, nowIso) {
    if (!iso) return '-';
    const diff = Date.parse(iso) - Date.parse(nowIso ?? new Date().toISOString());
    const abs = Math.abs(diff);
    const h = Math.floor(abs / 3600000);
    const m = Math.floor((abs % 3600000) / 60000);
    const text = h > 0 ? `${h}h ${m}m` : `${m}m`;
    return diff >= 0 ? `in ${text}` : `${text} ago`;
}

export const PAYMENT_PILL = {
    none: ['muted', 'Not started'],
    pending: ['warning', 'Pending'],
    failed: ['danger', 'Failed'],
    expired: ['muted', 'Link expired'],
    completed: ['success', 'Paid'],
    paid: ['success', 'Paid'],
};

export const EMAIL_LABELS = {
    invite: 'Invitation',
    waitlist: 'Waiting list',
    reminder: '2-hour reminder',
    expired: 'Invitation expired',
    receipt: 'Payment receipt',
    failed: 'Payment failed',
    live: 'Website live',
    registered: 'Registered',
    updated: 'Details updated',
};

export const ACTION_LABELS = {
    login: 'Signed in',
    logout: 'Signed out',
    login_failed: 'Failed sign-in',
    login_blocked: 'Sign-in blocked (too many tries)',
    admin_created: 'Admin created',
    admin_password_reset: 'Admin password reset',
    settings_saved: 'Settings saved',
    settings_test: 'Connection test',
    hermes_token_generated: 'New Hermes token created',
    campaign_saved: 'Campaign saved',
    export_suppliers: 'Suppliers exported',
    resend_email: 'Email resent',
    hermes_unreachable: 'Hermes unreachable (fixed chat used)',
    question_created: 'Question added',
    question_updated: 'Question edited',
    question_deleted: 'Question deleted',
    question_restored: 'Question restored',
    questions_reordered: 'Questions reordered',
    registration_question_created: 'Registration question added',
    registration_question_updated: 'Registration question edited',
    registration_question_deleted: 'Registration question deleted',
    registration_question_restored: 'Registration question restored',
    registration_questions_reordered: 'Registration questions reordered',
};
