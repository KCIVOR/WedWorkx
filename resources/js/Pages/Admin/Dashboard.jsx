import { Head, Link, usePage } from '@inertiajs/react';
import { Alert, ButtonLink, Note, Pill } from '../../Components/wb';
import { SlotMeter } from '../../Components/wb/forms';
import { AdminLayout, Panel, Stat } from '../../Components/admin/ui';
import { fmtDate, peso, relative } from '../../lib/admin';

export default function Dashboard({ campaign, waitlist_count, registered, statuses, money, attention, now }) {
    const base = `/${usePage().props.admin.path}`;
    const nothing = !attention.refunds.length && !attention.failed_emails && !attention.expiring_soon.length;

    return (
        <AdminLayout title="Overview" current="">
            <Head title="Overview" />

            <div className="grid gap-s-4 sm:grid-cols-2 lg:grid-cols-3">
                <Stat label="Winner slots" hint={`${waitlist_count} on the waiting list`}>
                    {campaign?.slots_taken ?? 0}
                    <span className="text-[.55em] text-wb-muted"> / {campaign?.slot_limit ?? 10}</span>
                    <div className="mt-s-3">
                        <SlotMeter taken={campaign?.slots_taken ?? 0} total={campaign?.slot_limit ?? 10} />
                    </div>
                </Stat>
                <Stat label="Collected" hint={`${money.orders} paid ${money.orders === 1 ? 'order' : 'orders'}`}>
                    <span className="mr-[2px] text-[.55em] text-wb-accent">₱</span>
                    {Number(money.collected).toLocaleString('en-PH')}
                </Stat>
                <Stat label="Registered" hint="Winners and waiting list together">{registered}</Stat>
            </div>

            <Panel title="Where everyone is" action={<ButtonLink variant="outline" href={`${base}/suppliers`}>See everyone</ButtonLink>}>
                <div className="grid grid-cols-2 gap-s-3 sm:grid-cols-4">
                    {statuses.map((s) => (
                        <Link
                            key={s.value}
                            href={`${base}/suppliers?status=${s.value}`}
                            className="grid min-w-0 content-start gap-s-2 rounded-[var(--r-md)] border border-wb-line bg-white p-[14px] no-underline transition duration-fast hover:border-wb-accent"
                        >
                            <Pill tone={s.tone}>{s.label}</Pill>
                            <b className="font-display text-[26px] font-bold tabular-nums text-wb-heading">{s.count}</b>
                        </Link>
                    ))}
                </div>
            </Panel>

            <Panel title="Needs attention">
                {nothing ? (
                    <Note icon="check">Nothing needs attention right now.</Note>
                ) : (
                    <div className="grid gap-s-3">
                        {attention.refunds.map((r) => (
                            <Alert key={r.supplier_id} tone="danger" title="Money received but no order was created">
                                <Link href={`${base}/suppliers/${r.supplier_id}`} className="font-semibold text-wb-accent">{r.business_name}</Link>{' '}
                                paid {peso(r.amount)}
                                {r.late ? ' after the deadline' : ''}. This needs a manual refund in HitPay.
                            </Alert>
                        ))}
                        {attention.failed_emails > 0 && (
                            <Alert tone="warning" title={`${attention.failed_emails} email${attention.failed_emails === 1 ? '' : 's'} failed to send`}>
                                Open the supplier and press Send again, and check the email settings.
                            </Alert>
                        )}
                        {attention.expiring_soon.map((s) => (
                            <Alert key={s.supplier_id} tone="warning" title="Invitation about to expire">
                                <Link href={`${base}/suppliers/${s.supplier_id}`} className="font-semibold text-wb-accent">{s.business_name}</Link>{' '}
                                expires {relative(s.expires_at, now)} ({fmtDate(s.expires_at)}).
                            </Alert>
                        ))}
                    </div>
                )}
            </Panel>
        </AdminLayout>
    );
}
