import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Button, Pill } from '../../Components/wb';
import { TextInput } from '../../Components/wb/forms';
import { AdminLayout, Pager, Select, Table, Td, Th } from '../../Components/admin/ui';
import { PAYMENT_PILL, fmtDate } from '../../lib/admin';

const empty = { q: '', status: '', date_from: '', date_to: '' };
const clean = (values) => Object.fromEntries(Object.entries(values).filter(([, value]) => value !== '' && value != null));
const currency = new Intl.NumberFormat('en-PH', {
    style: 'currency', currency: 'PHP', minimumFractionDigits: 2, maximumFractionDigits: 2,
});

export default function Payments({ payments, filters, options, server_now }) {
    const { props, url } = usePage();
    const base = `/${props.admin.path}/payments`;
    const errors = props.errors ?? {};
    const hasErrors = Object.keys(errors).length > 0;
    const [form, setForm] = useState(() => ({ ...empty, ...filters }));
    const [loading, setLoading] = useState(false);
    const busy = useRef(false);

    // History navigation restores the applied form. A validation redirect keeps the user's input.
    useEffect(() => {
        if (!hasErrors) setForm({ ...empty, ...filters });
    }, [filters, url, hasErrors]);

    useEffect(() => {
        const start = router.on('start', () => { busy.current = true; setLoading(true); });
        const finish = router.on('finish', () => { busy.current = false; setLoading(false); });
        return () => { start(); finish(); };
    }, []);

    const visit = (query) => {
        if (busy.current) return;
        busy.current = true;
        setLoading(true);
        router.get(base, clean(query), {
            preserveState: true, preserveScroll: true,
            onFinish: () => { busy.current = false; setLoading(false); },
        });
    };
    const fieldProps = (name) => ({
        id: `payment-${name}`, value: form[name],
        onChange: (event) => setForm((previous) => ({ ...previous, [name]: event.target.value })),
        'aria-invalid': !!errors[name],
        'aria-describedby': errors[name] ? `payment-${name}-error` : undefined,
    });
    const field = (name, label, input) => (
        <div className="grid gap-s-1">
            <label htmlFor={`payment-${name}`} className="text-[13px] font-semibold text-wb-heading">{label}</label>
            {input}
            {errors[name] && <p id={`payment-${name}-error`} className="text-[13px] text-red-700">{errors[name]}</p>}
        </div>
    );

    return (
        <AdminLayout title="Payment History" current="/payments">
            <Head title="Payment History" />
            <div className="grid gap-s-2 text-[13.5px] text-wb-muted">
                <p>Stored payment attempts with their current status, not a record of every status transition. Pending means awaiting confirmation. Errors before an attempt is created do not appear here.</p>
                <p>Simulated attempts are marked. Other attempts may use sandbox or live payments. Refresh reloads stored data; it does not contact HitPay. Open supplier details for payment attention; an attempt does not identify an order.</p>
                <p>Dates and start-date filters use Manila time. Awaiting expiry update means a pending attempt has passed its expiry time but its stored status has not yet changed. Snapshot: {fmtDate(server_now)}.</p>
            </div>

            <form onSubmit={(event) => { event.preventDefault(); visit({ ...form, page: 1 }); }} aria-busy={loading}
                className="rounded-r-card border border-wb-line bg-white p-[18px] shadow-sh-sm">
                {hasErrors && <p role="alert" className="mb-s-3 text-[13px] text-red-700">Please correct the filters. {Object.entries(errors).filter(([name]) => !(name in empty)).map(([, message]) => message).join(' ')}</p>}
                <fieldset disabled={loading} className="grid min-w-0 gap-s-3 md:grid-cols-[minmax(0,2fr)_repeat(3,minmax(0,1fr))]">
                    <legend className="sr-only">Payment history filters</legend>
                    {field('q', 'Search', <TextInput type="search" placeholder="Supplier, business or request ID" {...fieldProps('q')} />)}
                    {field('status', 'Status', <Select {...fieldProps('status')}>
                        <option value="">Every status</option>
                        {options.statuses.map((status) => <option key={status.value} value={status.value}>{status.label}</option>)}
                    </Select>)}
                    {field('date_from', 'Started from (Manila)', <TextInput type="date" {...fieldProps('date_from')} />)}
                    {field('date_to', 'Started through (Manila)', <TextInput type="date" {...fieldProps('date_to')} />)}
                    <div className="flex flex-wrap gap-s-2 md:col-span-4">
                        <Button type="submit">Apply</Button>
                        <Button type="button" variant="outline" onClick={() => { setForm({ ...empty }); visit({ page: 1 }); }}>Clear</Button>
                        <Button type="button" variant="outline" onClick={() => visit({ ...filters, page: payments.current_page })}>Refresh</Button>
                    </div>
                </fieldset>
            </form>
            <p role="status" aria-live="polite" className="text-[13px] text-wb-muted">
                {loading ? 'Loading payment history… ' : ''}
                {payments.from != null ? `${payments.from}–${payments.to} of ${payments.total} attempts` : `${payments.total} attempts`}
            </p>
            <Table>
                <thead><tr><Th>ID</Th><Th>Supplier</Th><Th>Amount (PHP)</Th><Th>Status</Th><Th>Started (Manila)</Th><Th>Paid (Manila)</Th><Th>Expires (Manila)</Th><Th>Request ID</Th></tr></thead>
                <tbody>
                    {payments.data.length === 0 && <tr><Td colSpan={8} className="py-s-8 text-center text-wb-muted">No payment attempts match these filters.</Td></tr>}
                    {payments.data.map((payment) => {
                        const [tone, label] = PAYMENT_PILL[payment.status] ?? ['muted', payment.status];
                        return <tr key={payment.id} className="transition duration-fast hover:bg-blue-50">
                            <Td>{payment.id}</Td>
                            <Td className="min-w-[190px]">{payment.supplier ? <>
                                <Link href={`/${props.admin.path}/suppliers/${payment.supplier.id}`} className="font-display font-semibold text-wb-accent no-underline hover:underline">{payment.supplier.business_name || payment.supplier.name}</Link>
                                <div>{payment.supplier.name}</div>
                            </> : <span className="text-wb-muted">Supplier unavailable</span>}</Td>
                            <Td className="whitespace-nowrap tabular-nums">{currency.format(Number(payment.amount))}</Td>
                            <Td className="min-w-[170px]"><div className="flex flex-wrap gap-s-1">
                                <Pill tone={tone}>{label}</Pill>
                                {payment.late && <Pill tone="warning">Late</Pill>}
                                {payment.simulated && <Pill tone="info">Simulated</Pill>}
                                {payment.awaiting_expiry_update && <Pill tone="warning">Awaiting expiry update</Pill>}
                            </div></Td>
                            <Td className="whitespace-nowrap">{fmtDate(payment.started_at)}</Td>
                            <Td className="whitespace-nowrap">{fmtDate(payment.paid_at)}</Td>
                            <Td className="whitespace-nowrap">{fmtDate(payment.expires_at)}</Td>
                            <Td className="min-w-[180px] break-words [overflow-wrap:anywhere]">{payment.request_id}</Td>
                        </tr>;
                    })}
                </tbody>
            </Table>
            <fieldset disabled={loading} className="min-w-0">
                <legend className="sr-only">Payment history pages</legend>
                <Pager page={payments} base={base} query={clean(filters)} />
            </fieldset>
        </AdminLayout>
    );
}
