import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button, ButtonLink, Pill } from '../../Components/wb';
import { TextInput } from '../../Components/wb/forms';
import { AdminLayout, Pager, Select, Table, Td, Th } from '../../Components/admin/ui';
import { PAYMENT_PILL, fmtDate, peso } from '../../lib/admin';

const canApprove = (s) => s.status === 'pending_review' || s.status === 'waitlisted';

export default function Suppliers({ suppliers, filters, options, slots }) {
    const base = `/${usePage().props.admin.path}`;
    const [f, setF] = useState(filters);
    const set = (k, v) => setF((s) => ({ ...s, [k]: v }));
    const [picked, setPicked] = useState([]);
    const [busy, setBusy] = useState(false);
    const remaining = Math.max(slots.limit - slots.used, 0);
    const toggle = (id) => setPicked((p) => (p.includes(id) ? p.filter((x) => x !== id) : [...p, id]));
    const approve = () => {
        const msg = `Approve ${picked.length} supplier${picked.length === 1 ? '' : 's'} as winner${picked.length === 1 ? '' : 's'}? `
            + 'They will be emailed the Bot 2 link now and their deadline starts today.';
        if (!picked.length || !window.confirm(msg)) return;
        router.post(`${base}/suppliers/approve`, { ids: picked }, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => { setBusy(false); setPicked([]); },
        });
    };

    const clean = (obj) => Object.fromEntries(Object.entries(obj).filter(([, v]) => v !== '' && v != null));
    const apply = (e) => {
        e?.preventDefault();
        router.get(`${base}/suppliers`, clean(f), { preserveState: true });
    };
    const reset = () => {
        setF({ q: '', status: '', group: '', type: '' });
        router.get(`${base}/suppliers`, {}, { preserveState: true });
    };
    const exportHref = `${base}/suppliers/export?${new URLSearchParams(clean(filters)).toString()}`;

    return (
        <AdminLayout title="Suppliers" current="/suppliers">
            <Head title="Suppliers" />

            <form onSubmit={apply} className="grid gap-s-3 rounded-r-card border border-wb-line bg-white p-[18px] shadow-sh-sm md:grid-cols-[minmax(0,2fr)_repeat(3,minmax(0,1fr))]">
                <TextInput type="search" placeholder="Search name, business, email or mobile" aria-label="Search" value={f.q} onChange={(e) => set('q', e.target.value)} />
                <Select aria-label="Status" value={f.status} onChange={(e) => set('status', e.target.value)}>
                    <option value="">Every status</option>
                    {options.statuses.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                </Select>
                <Select aria-label="Winners or waiting list" value={f.group} onChange={(e) => set('group', e.target.value)}>
                    <option value="">Everyone</option>
                    <option value="winners">Winners only</option>
                    <option value="waitlist">Waiting list only</option>
                </Select>
                <Select aria-label="Business type" value={f.type} onChange={(e) => set('type', e.target.value)}>
                    <option value="">Every type</option>
                    {options.types.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                </Select>
                <div className="flex flex-wrap gap-s-2 md:col-span-4">
                    <Button type="submit">Apply</Button>
                    <Button variant="outline" onClick={reset}>Clear</Button>
                    <span className="flex-1" />
                    <ButtonLink variant="outline" href={exportHref}>Download CSV ({suppliers.total})</ButtonLink>
                </div>
            </form>

            <div className="flex flex-wrap items-center gap-s-3 rounded-r-card border border-wb-line bg-white p-[14px] shadow-sh-sm">
                <div className="text-wb-heading">
                    <strong>{slots.used} of {slots.limit}</strong> winner slots used · <strong>{remaining}</strong> left
                </div>
                <span className="flex-1" />
                {picked.length > remaining && <span className="text-[12.5px] text-wb-muted">Only the first {remaining} will be approved.</span>}
                <Button onClick={approve} disabled={busy || picked.length === 0 || remaining === 0}>
                    Approve {picked.length || ''} as winner{picked.length === 1 ? '' : 's'} and send Bot 2 link
                </Button>
            </div>

            <Table>
                <thead>
                    <tr>
                        <Th><span className="sr-only">Select</span></Th><Th>Spot</Th><Th>Supplier</Th><Th>Contact</Th><Th>Registered</Th><Th>Status</Th>
                        <Th>Answers</Th><Th>Payment</Th><Th>Website</Th><Th>Emails</Th>
                    </tr>
                </thead>
                <tbody>
                    {suppliers.data.length === 0 && (
                        <tr><Td className="py-s-8 text-center text-wb-muted" colSpan={10}>Nobody matches these filters.</Td></tr>
                    )}
                    {suppliers.data.map((s) => {
                        const [ptone, plabel] = PAYMENT_PILL[s.payment.state] ?? PAYMENT_PILL.none;
                        return (
                            <tr key={s.id} className="transition duration-fast hover:bg-blue-50">
                                <Td>
                                    {canApprove(s) && (
                                        <input type="checkbox" aria-label={`Select ${s.business_name}`} checked={picked.includes(s.id)} onChange={() => toggle(s.id)} />
                                    )}
                                </Td>
                                <Td className="whitespace-nowrap">
                                    {s.slot ? <Pill tone="success">Slot {s.slot}</Pill>
                                        : s.waitlist ? <Pill tone="muted">Waitlist {s.waitlist}</Pill>
                                        : <Pill tone="warning">Awaiting approval</Pill>}
                                </Td>
                                <Td className="min-w-[190px]">
                                    <Link href={`${base}/suppliers/${s.id}`} className="font-display font-semibold text-wb-accent no-underline hover:underline">{s.business_name}</Link>
                                    <div className="text-wb-heading">{s.name}</div>
                                    <div className="text-[12.5px] text-wb-muted">{s.business_type} · {s.city}</div>
                                </Td>
                                <Td className="min-w-[210px]">
                                    <div className="break-words [overflow-wrap:anywhere]">{s.email}</div>
                                    <div className="text-[12.5px] text-wb-muted">{s.mobile}</div>
                                </Td>
                                <Td className="whitespace-nowrap">{fmtDate(s.registered_at, true, false)}</Td>
                                <Td><Pill tone={s.status_tone}>{s.status_label}</Pill></Td>
                                <Td>{s.answers_confirmed_at ? <Pill tone="success">Confirmed</Pill> : <span className="text-wb-muted">-</span>}</Td>
                                <Td className="whitespace-nowrap">
                                    {s.payment.state === 'none' && !s.slot ? <span className="text-wb-muted">-</span> : <Pill tone={ptone}>{plabel}</Pill>}
                                    {s.payment.state === 'paid' && <div className="mt-[4px] text-[12.5px] text-wb-muted">{peso(s.payment.amount)} · {s.reference ?? 'no order'}</div>}
                                    {s.payment.needs_review && <div className="mt-[4px]"><Pill tone="danger">Needs refund</Pill></div>}
                                </Td>
                                <Td className="whitespace-nowrap">
                                    {s.stage == null ? <span className="text-wb-muted">-</span> : s.website_url
                                        ? <a href={s.website_url} target="_blank" rel="noreferrer" className="font-semibold text-wb-accent">Live</a>
                                        : <span>Stage {s.stage} / 6</span>}
                                </Td>
                                <Td className="whitespace-nowrap">
                                    {s.emails_sent} sent
                                    {s.emails_failed > 0 && <div className="mt-[4px]"><Pill tone="danger">{s.emails_failed} failed</Pill></div>}
                                </Td>
                            </tr>
                        );
                    })}
                </tbody>
            </Table>

            <Pager page={suppliers} base={`${base}/suppliers`} query={clean(filters)} />
        </AdminLayout>
    );
}
