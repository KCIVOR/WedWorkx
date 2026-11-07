import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { Alert, Icon, Pill } from '../wb';

/**
 * Admin building blocks. Everything here is composed from the design system's tokens and the existing
 * wb-* components (accent bar, card, pill, button, alert, field). The table and the stat tile are not
 * in the design system document: they are flagged in the build log as composed from existing tokens.
 */

const NAV = [
    ['', 'Overview'],
    ['/suppliers', 'Suppliers'],
    ['/questions', 'Questions'],
    ['/settings', 'Settings'],
    ['/activity', 'Activity'],
];

export function AdminLayout({ title, current = '', children }) {
    const { admin, dev, flash } = usePage().props;
    const base = `/${admin?.path ?? 'admin'}`;

    return (
        <div className="min-h-screen bg-wb-canvas font-body text-wb-text">
            <header className="bg-wb-accent text-white">
                <div className="mx-auto flex max-w-[1320px] flex-wrap items-center gap-x-s-5 gap-y-s-2 px-s-4 py-s-3">
                    <Link href={base} className="flex items-center gap-s-3 text-white no-underline">
                        <span className="grid h-[36px] w-[36px] place-items-center rounded-full bg-white text-[20px] text-wb-accent">
                            <Icon name="rings" />
                        </span>
                        <strong className="font-display text-[16px] font-bold tracking-[-.01em]">WedWorks Admin</strong>
                    </Link>

                    <nav className="order-last flex w-full flex-wrap gap-s-1 md:order-none md:w-auto md:flex-1" aria-label="Admin">
                        {NAV.map(([path, label]) => {
                            const active = current === path;
                            return (
                                <Link
                                    key={label}
                                    href={base + path}
                                    aria-current={active ? 'page' : undefined}
                                    className={`rounded-r-pill px-s-4 py-s-2 font-display text-[13.5px] font-semibold no-underline transition duration-fast ${
                                        active ? 'bg-white text-wb-accent' : 'text-white/85 hover:bg-white/15'
                                    }`}
                                >
                                    {label}
                                </Link>
                            );
                        })}
                    </nav>

                    <div className="ml-auto flex items-center gap-s-3 md:ml-0">
                        {dev && (
                            <span className="rounded-r-pill bg-amber-50 px-s-3 py-s-1 text-[11px] font-semibold uppercase tracking-[.08em] text-amber-700">
                                Dev mode
                            </span>
                        )}
                        <span className="hidden text-[12.5px] text-white/80 sm:inline">{admin?.email}</span>
                        <button
                            type="button"
                            onClick={() => router.post(`${base}/logout`)}
                            className="rounded-r-pill border border-white/40 bg-transparent px-s-4 py-s-2 font-display text-[13px] font-semibold text-white transition duration-fast hover:bg-white/15"
                        >
                            Sign out
                        </button>
                    </div>
                </div>
            </header>

            <main className="mx-auto grid max-w-[1320px] gap-s-5 px-s-4 py-s-6">
                <h1 className="font-display text-[26px] font-bold leading-[1.15] tracking-[-.02em] text-wb-heading">{title}</h1>
                {flash?.admin_flash && <Alert tone={flash.admin_flash.tone}>{flash.admin_flash.message}</Alert>}
                {children}
            </main>
        </div>
    );
}

export function Panel({ title, action, children, className = '' }) {
    return (
        <section className={`min-w-0 rounded-r-card border border-wb-line bg-white p-[18px] shadow-sh-sm ${className}`}>
            {(title || action) && (
                <div className="mb-s-3 flex flex-wrap items-center justify-between gap-s-3">
                    {title && <h2 className="font-display text-[17px] font-bold tracking-[-.01em] text-wb-heading">{title}</h2>}
                    {action}
                </div>
            )}
            {children}
        </section>
    );
}

export function Stat({ label, children, hint }) {
    return (
        <div className="min-w-0 rounded-r-card border border-wb-line bg-white p-[18px] shadow-sh-sm">
            <div className="text-[11px] font-semibold uppercase tracking-[.08em] text-wb-muted">{label}</div>
            <div className="mt-s-2 font-display text-[34px] font-bold leading-none tracking-[-.03em] tabular-nums text-wb-heading">{children}</div>
            {hint && <div className="mt-s-2 text-[13px] text-wb-muted">{hint}</div>}
        </div>
    );
}

const SELECT =
    'w-full rounded-r-field border-[1.5px] border-slate-300 bg-white px-[14px] py-[11px] font-[inherit] text-[14.5px] text-wb-heading outline-none transition duration-fast focus:border-wb-accent focus:shadow-[0_0_0_4px_var(--wb-accent-soft)]';

export function Select({ children, ...props }) {
    return (
        <select {...props} className={SELECT}>
            {children}
        </select>
    );
}

export function Table({ children }) {
    return (
        <div className="overflow-x-auto rounded-r-card border border-wb-line bg-white shadow-sh-sm">
            <table className="w-full min-w-[1040px] border-collapse text-left text-[13.5px]">{children}</table>
        </div>
    );
}

export const Th = ({ children }) => (
    <th className="whitespace-nowrap border-b border-wb-line bg-slate-50 px-s-3 py-s-3 text-[11px] font-semibold uppercase tracking-[.08em] text-wb-muted">{children}</th>
);

export const Td = ({ children, className = '', ...rest }) => (
    <td {...rest} className={`border-b border-wb-line px-s-3 py-[12px] align-top text-wb-text ${className}`}>{children}</td>
);

export function SourcePill({ source }) {
    const map = { admin: ['success', 'Saved here'], env: ['info', 'From .env'], unset: ['muted', 'Not set'] };
    const [tone, label] = map[source] ?? map.unset;
    return <Pill tone={tone}>{label}</Pill>;
}

export function Pager({ page, base, query }) {
    if (page.last_page <= 1) return null;
    const go = (n) => router.get(base, { ...query, page: n }, { preserveScroll: true, preserveState: true });
    return (
        <div className="flex flex-wrap items-center justify-between gap-s-3 text-[13px] text-wb-muted">
            <span>
                {page.from != null ? `${page.from}-${page.to} of ${page.total}` : `Page ${page.current_page} of ${page.last_page}`}
            </span>
            <div className="flex gap-s-2">
                <button type="button" disabled={page.current_page <= 1} onClick={() => go(page.current_page - 1)}
                    className="min-h-[40px] rounded-r-pill border border-slate-300 bg-white px-s-5 font-display text-[13px] font-semibold text-wb-heading hover:border-wb-accent hover:text-wb-accent disabled:cursor-default disabled:opacity-40">
                    Previous
                </button>
                <button type="button" disabled={page.current_page >= page.last_page} onClick={() => go(page.current_page + 1)}
                    className="min-h-[40px] rounded-r-pill border border-slate-300 bg-white px-s-5 font-display text-[13px] font-semibold text-wb-heading hover:border-wb-accent hover:text-wb-accent disabled:cursor-default disabled:opacity-40">
                    Next
                </button>
            </div>
        </div>
    );
}

/**
 * A dialog over the page, so a form can be filled in without scrolling back to the top.
 * Escape, the close button and a click on the dimmed area close it; the page behind does not scroll.
 */
export function Modal({ title, onClose, children }) {
    const closeRef = useRef(onClose);
    const dialogRef = useRef(null);
    closeRef.current = onClose;

    useEffect(() => {
        const onKey = (e) => { if (e.key === 'Escape') closeRef.current(); };
        document.addEventListener('keydown', onKey);
        const previous = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        dialogRef.current?.querySelector('input:not([disabled]), textarea, select:not([disabled])')?.focus();
        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = previous;
        };
    }, []);

    return (
        <div
            className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/50 sm:items-center sm:p-s-4"
            onMouseDown={(e) => { if (e.target === e.currentTarget) closeRef.current(); }}
        >
            <div
                ref={dialogRef}
                role="dialog"
                aria-modal="true"
                aria-label={title}
                className="flex max-h-[92dvh] w-full max-w-[680px] flex-col rounded-t-[20px] bg-white shadow-[0_24px_64px_rgba(15,23,42,.3)] sm:rounded-r-card"
            >
                <div className="flex items-start justify-between gap-s-3 border-b border-wb-line px-[18px] py-s-3">
                    <h2 className="font-display text-[17px] font-bold tracking-[-.01em] text-wb-heading">{title}</h2>
                    <button
                        type="button"
                        aria-label="Close"
                        onClick={() => closeRef.current()}
                        className="grid h-[36px] w-[36px] shrink-0 place-items-center rounded-full text-[18px] text-wb-muted hover:bg-slate-100 hover:text-wb-heading"
                    >
                        <Icon name="x" />
                    </button>
                </div>
                <div className="overflow-y-auto px-[18px] pt-s-4">{children}</div>
            </div>
        </div>
    );
}
