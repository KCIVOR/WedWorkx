import { useEffect, useState } from 'react';
import { Icon } from './index';

/* Form fields, slot meter and countdown from the design system (wb-input, wb-textarea, wb-slots, wb-count). */

const FIELD =
    'w-full rounded-r-field border-[1.5px] border-slate-300 bg-white px-[14px] py-[11px] font-[inherit] text-[14.5px] text-wb-heading outline-none transition duration-fast placeholder:text-wb-placeholder focus:border-wb-accent focus:shadow-[0_0_0_4px_var(--wb-accent-soft)]';

export function TextInput({ invalid, className = '', ...props }) {
    return <input {...props} className={`${FIELD} ${invalid ? 'border-red-600 shadow-[0_0_0_4px_rgba(198,40,40,.10)]' : ''} ${className}`} />;
}

export function Textarea({ invalid, ...props }) {
    return <textarea {...props} className={`${FIELD} min-h-[84px] resize-y ${invalid ? 'border-red-600' : ''}`} />;
}

export function Field({ label, help, children }) {
    return (
        <div className="grid gap-[6px] text-left">
            {label && <label className="text-[13px] font-semibold text-wb-heading">{label}</label>}
            {children}
            {help && <div className="text-[12.5px] text-wb-muted">{help}</div>}
        </div>
    );
}

export function SlotMeter({ taken, total, label }) {
    return (
        <div className="grid w-full gap-s-1" style={{ gridTemplateColumns: `repeat(${total}, 1fr)` }} role="img" aria-label={label ?? `${taken} of ${total} slots taken`}>
            {Array.from({ length: total }, (_, i) => (
                <i key={i} className={`h-[6px] rounded-[3px] ${i < taken ? 'bg-wb-accent' : 'bg-slate-200'}`} />
            ))}
        </div>
    );
}

/** Hours / minutes / seconds to `expiresAt`. `serverNow` corrects for a wrong clock on the phone. */
export function Countdown({ expiresAt, serverNow, onDone }) {
    const [offset] = useState(() => Date.parse(serverNow) - Date.now());
    const remaining = () => Math.max(0, Date.parse(expiresAt) - (Date.now() + offset));
    const [ms, setMs] = useState(remaining);

    useEffect(() => {
        const t = setInterval(() => {
            const r = remaining();
            setMs(r);
            if (r <= 0) {
                clearInterval(t);
                onDone?.();
            }
        }, 1000);
        return () => clearInterval(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [expiresAt]);

    const h = Math.floor(ms / 3600000);
    const m = Math.floor((ms % 3600000) / 60000);
    const s = Math.floor((ms % 60000) / 1000);
    const box = (n, label) => (
        <div className="rounded-[14px] border border-wb-line bg-slate-50 px-s-1 py-[10px] text-center">
            <b className="block font-display text-[22px] font-bold tabular-nums text-wb-heading">{String(n).padStart(2, '0')}</b>
            <span className="text-[10px] uppercase tracking-[.08em] text-wb-muted">{label}</span>
        </div>
    );

    return (
        <div className="grid w-full grid-cols-3 gap-s-2" aria-live="off">
            {box(h, 'Hours')}
            {box(m, 'Minutes')}
            {box(s, 'Seconds')}
        </div>
    );
}

/** Small image tile with a remove button, composed from existing tokens (flagged in the build log). */
export function Thumb({ upload, onRemove, disabled }) {
    return (
        <div className="relative h-[56px] w-[56px] flex-none">
            <img
                src={upload.url}
                alt={upload.name}
                className="h-full w-full rounded-[var(--r-sm)] border border-wb-line object-cover"
            />
            {upload.kind === 'logo' && (
                <span className="absolute bottom-[2px] left-[2px] rounded-r-pill bg-wb-accent px-[6px] text-[10px] font-semibold text-white">Logo</span>
            )}
            <button
                type="button"
                onClick={onRemove}
                disabled={disabled}
                aria-label={`Remove ${upload.name}`}
                className="absolute -right-[6px] -top-[6px] grid h-[22px] w-[22px] place-items-center rounded-full border border-wb-line bg-white text-[12px] text-wb-heading shadow-sh-sm hover:text-red-600 disabled:opacity-40"
            >
                <Icon name="x" />
            </button>
        </div>
    );
}
