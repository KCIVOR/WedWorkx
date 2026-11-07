import { Head, usePage } from '@inertiajs/react';
import { Pill } from '../../Components/wb';
import { AdminLayout, Pager, Table, Td, Th } from '../../Components/admin/ui';
import { ACTION_LABELS, fmtDate } from '../../lib/admin';

export default function Audit({ logs }) {
    const base = `/${usePage().props.admin.path}`;
    const failed = (a) => a === 'login_failed' || a === 'login_blocked';

    return (
        <AdminLayout title="Activity" current="/activity">
            <Head title="Activity" />
            <p className="-mt-s-3 text-[14px] text-wb-muted">What admins did. It lists which settings changed, never their values.</p>

            <Table>
                <thead><tr><Th>When</Th><Th>Who</Th><Th>What</Th><Th>Details</Th><Th>IP</Th></tr></thead>
                <tbody>
                    {logs.data.length === 0 && <tr><Td colSpan={5} className="py-s-8 text-center text-wb-muted">Nothing yet.</Td></tr>}
                    {logs.data.map((l) => (
                        <tr key={l.id}>
                            <Td className="whitespace-nowrap">{fmtDate(l.at)}</Td>
                            <Td className="break-all">{l.who ?? <span className="text-wb-muted">-</span>}</Td>
                            <Td><Pill tone={failed(l.action) ? 'danger' : 'info'}>{ACTION_LABELS[l.action] ?? l.action}</Pill></Td>
                            <Td className="break-words text-[12.5px] text-wb-muted">
                                {l.detail ? Object.entries(l.detail).map(([k, v]) => `${k}: ${Array.isArray(v) ? v.join(', ') : String(v)}`).join(' · ') : '-'}
                            </Td>
                            <Td className="whitespace-nowrap text-[12.5px] text-wb-muted">{l.ip ?? '-'}</Td>
                        </tr>
                    ))}
                </tbody>
            </Table>
            <Pager page={{ ...logs, from: null, to: null }} base={`${base}/activity`} query={{}} />
        </AdminLayout>
    );
}
