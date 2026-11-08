import { Head, Link, router, usePage } from '@inertiajs/react';
import { Note, PayRow, Pill, ReviewRow, ReviewRows } from '../../Components/wb';
import { SlotMeter } from '../../Components/wb/forms';
import { AdminLayout, Panel } from '../../Components/admin/ui';
import { EMAIL_LABELS, PAYMENT_PILL, fmtDate, peso } from '../../lib/admin';

export default function SupplierShow({ supplier: s, answers, registration_answers = [], uploads, timeline, payments, stages, emails }) {
    const base = `/${usePage().props.admin.path}`;
    const [ptone, plabel] = PAYMENT_PILL[s.payment.state] ?? PAYMENT_PILL.none;

    return (
        <AdminLayout title={s.business_name} current="/suppliers">
            <Head title={s.business_name} />
            <div><Link href={`${base}/chats?supplier=${s.id}`} className="text-[14px] font-semibold text-wb-accent">View live chats →</Link></div>

            <div className="-mt-s-3 flex flex-wrap items-center gap-s-3">
                <Link href={`${base}/suppliers`} className="text-[13.5px] font-semibold text-wb-accent no-underline hover:underline">&larr; All suppliers</Link>
                <Pill tone={s.status_tone}>{s.status_label}</Pill>
                {s.slot ? <Pill tone="success">Slot {s.slot}</Pill> : <Pill tone="muted">Waitlist {s.waitlist}</Pill>}
            </div>

            <div className="grid items-start gap-s-5 lg:grid-cols-2">
                <Panel title="Profile">
                    <div className="grid">
                        <PayRow label="Name">{s.name}</PayRow>
                        <PayRow label="Business">{s.business_name}</PayRow>
                        <PayRow label="Type">{s.business_type}</PayRow>
                        <PayRow label="Email"><span className="break-all">{s.email}</span></PayRow>
                        <PayRow label="Mobile">{s.mobile}</PayRow>
                        <PayRow label="Location">{s.city}, {s.province}</PayRow>
                        <PayRow label="Social link">
                            {s.social_link ? <a href={s.social_link} target="_blank" rel="noreferrer" className="break-all text-wb-accent">{s.social_link}</a> : '-'}
                        </PayRow>
                        {registration_answers.map((a) => <PayRow key={a.label} label={a.label}>{a.value}</PayRow>)}
                        <PayRow label="Registered">{fmtDate(s.registered_at)}</PayRow>
                    </div>
                </Panel>

                <Panel title="Progress">
                    <div className="grid">
                        <PayRow label="Invitation deadline">{s.invite_expires_at ? fmtDate(s.invite_expires_at) : '-'}</PayRow>
                        <PayRow label="Answers confirmed">{s.answers_confirmed_at ? fmtDate(s.answers_confirmed_at) : 'Not yet'}</PayRow>
                        <PayRow label="Payment"><Pill tone={ptone}>{plabel}</Pill></PayRow>
                        <PayRow label="Amount">{s.payment.amount != null ? peso(s.payment.amount) : '-'}</PayRow>
                        <PayRow label="Reference">{s.reference ?? '-'}</PayRow>
                        <PayRow label="Production stage">{s.stage == null ? '-' : `${s.stage} of 6`}</PayRow>
                        <PayRow label="Website">
                            {s.website_url ? <a href={s.website_url} target="_blank" rel="noreferrer" className="break-all text-wb-accent">{s.website_url}</a> : '-'}
                        </PayRow>
                    </div>
                    {s.stage != null && (
                        <div className="mt-s-3"><SlotMeter taken={s.stage} total={6} label={`Stage ${s.stage} of 6`} /></div>
                    )}
                    {s.payment.needs_review && (
                        <div className="mt-s-3"><Note icon="alert">A payment arrived but no order exists (late or repeated payment). Refund it in HitPay.</Note></div>
                    )}
                </Panel>
            </div>

            <Panel title="Website answers">
                {answers.length === 0 ? (
                    <Note>This supplier has not answered the website questions yet.</Note>
                ) : (
                    <ReviewRows>
                        {answers.map((a) => <ReviewRow key={a.label} label={a.label} value={a.value ?? <span className="text-wb-muted">Skipped</span>} />)}
                    </ReviewRows>
                )}
            </Panel>

            <Panel title={`Uploads (${uploads.length})`}>
                {uploads.length === 0 ? (
                    <Note>No logo or photos uploaded.</Note>
                ) : (
                    <div className="grid grid-cols-2 gap-s-3 sm:grid-cols-4 lg:grid-cols-6">
                        {uploads.map((u) => (
                            <a key={u.id} href={u.url} target="_blank" rel="noreferrer" className="grid min-w-0 gap-s-1 no-underline">
                                {u.image
                                    ? <img src={u.url} alt={u.name} className="aspect-square w-full rounded-[var(--r-md)] border border-wb-line object-cover" />
                                    : <span className="grid aspect-square place-items-center rounded-[var(--r-md)] border border-wb-line bg-slate-50 text-[12px] text-wb-muted">{u.name}</span>}
                                <span className="truncate text-[12px] text-wb-muted">{u.kind === 'logo' ? 'Logo' : 'Photo'} · {u.size_kb} KB</span>
                            </a>
                        ))}
                    </div>
                )}
            </Panel>

            <div className="grid items-start gap-s-5 lg:grid-cols-2">
                <Panel title="Payment attempts">
                    {payments.length === 0 ? <Note>No payment started.</Note> : (
                        <div className="grid">
                            {payments.map((p) => {
                                const [t, l] = PAYMENT_PILL[p.status] ?? PAYMENT_PILL.none;
                                return (
                                    <PayRow key={p.id} label={fmtDate(p.started_at)}>
                                        <span className="inline-flex flex-wrap items-center justify-end gap-s-2">
                                            {peso(p.amount)} <Pill tone={t}>{l}</Pill>{p.late && <Pill tone="warning">Late</Pill>}
                                        </span>
                                    </PayRow>
                                );
                            })}
                        </div>
                    )}
                </Panel>

                <Panel title="Status history">
                    {timeline.length === 0 ? <Note>No changes yet.</Note> : (
                        <div className="grid">
                            {timeline.map((h, i) => (
                                <PayRow key={i} label={fmtDate(h.at)}>
                                    {h.from ? `${h.from} → ` : ''}{h.to} <span className="font-normal text-wb-muted">({h.actor})</span>
                                </PayRow>
                            ))}
                        </div>
                    )}
                </Panel>
            </div>

            {stages.length > 0 && (
                <Panel title="Hermes stage notes">
                    <div className="grid">
                        {stages.map((l, i) => (
                            <PayRow key={i} label={fmtDate(l.at)}>Stage {l.stage}{l.note ? `: ${l.note}` : ''}</PayRow>
                        ))}
                    </div>
                </Panel>
            )}

            <Panel title="Emails">
                {emails.length === 0 ? <Note>No emails yet.</Note> : (
                    <div className="grid">
                        {emails.map((e) => (
                            <PayRow key={e.id} label={`${EMAIL_LABELS[e.type] ?? e.type} · ${fmtDate(e.at)}`}>
                                <span className="inline-flex flex-wrap items-center justify-end gap-s-2">
                                    <Pill tone={e.status === 'sent' ? 'success' : e.status === 'failed' ? 'danger' : 'muted'}>{e.status}</Pill>
                                    {e.can_resend && (
                                        <button type="button" onClick={() => router.post(`${base}/suppliers/${s.id}/emails/${e.id}/resend`, {}, { preserveScroll: true })}
                                            className="rounded-r-pill border border-slate-300 bg-white px-s-3 py-s-1 text-[12.5px] font-semibold text-wb-heading hover:border-wb-accent hover:text-wb-accent">
                                            Send again
                                        </button>
                                    )}
                                </span>
                            </PayRow>
                        ))}
                    </div>
                )}
            </Panel>
        </AdminLayout>
    );
}
