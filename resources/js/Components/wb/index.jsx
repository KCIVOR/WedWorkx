import { usePage } from '@inertiajs/react';
import { chatTime } from '../../lib/chatTime';
/**
 * React versions of the design system's wb-* components
 * (docs/WedWorks_Chatbot_Design_System.html). Values match its CSS one to one,
 * expressed through the Tailwind tokens from tailwind.config.js.
 */

/* ---- Icons: paths copied from the design system's icon sprite ---- */
const ICON_PATHS = {
    rings: (<><circle cx="9.5" cy="12" r="5" /><circle cx="14.5" cy="12" r="5" /></>),
    send: <path d="M5 12h13M12 5l7 7-7 7" />,
    attach: <path d="M21 11.5l-8.6 8.6a5 5 0 01-7.1-7.1l8.6-8.6a3.3 3.3 0 014.7 4.7l-8.6 8.6a1.7 1.7 0 01-2.4-2.4l7.9-7.9" />,
    check: <path d="M5 12.5l4.5 4.5L19 7.5" />,
    x: <path d="M6 6l12 12M18 6L6 18" />,
    pause: <path d="M9 6v12M15 6v12" />,
    retry: (<path d="M4 12a8 8 0 108-8H8M8 4L5 7l3 3" />),
    clock: (<><circle cx="12" cy="12" r="8.5" /><path d="M12 7.5V12l3 2" /></>),
    mail: (<><rect x="3.5" y="5.5" width="17" height="13" rx="2.5" /><path d="M4 7.5l8 5.5 8-5.5" /></>),
    info: (<><circle cx="12" cy="12" r="8.5" /><path d="M12 11v5M12 8v.01" /></>),
    alert: (<><path d="M12 4l9 15.5H3L12 4z" /><path d="M12 10v4.5M12 17.2v.01" /></>),
};

export function Icon({ name, className = '' }) {
    return (
        <svg
            aria-hidden="true"
            viewBox="0 0 24 24"
            className={`h-[1em] w-[1em] flex-none fill-none stroke-current ${className}`}
            style={{ strokeWidth: 1.8, strokeLinecap: 'round', strokeLinejoin: 'round' }}
        >
            {ICON_PATHS[name]}
        </svg>
    );
}

/* ---- Shell, header, log ---- */
export function Shell({ children }) {
    return (
        <div className="flex h-full flex-col overflow-hidden bg-wb-surface font-body text-wb-text md:rounded-r-shell md:border md:border-wb-line md:shadow-sh-md">
            {children}
            <footer className="flex-none border-t border-wb-line bg-wb-surface px-s-4 pt-[8px] pb-[max(8px,env(safe-area-inset-bottom))] text-center text-[11px] leading-[1.5] text-wb-muted">
                Powered by <span className="font-semibold text-wb-heading">StartupLab Business Center</span>
            </footer>
        </div>
    );
}

export function Header({ title, status = 'Online' }) {
    const dev = usePage().props.dev;
    return (
        <div className="relative z-[1] flex flex-none items-center gap-s-3 md:rounded-b-r-shell bg-wb-accent px-s-4 pb-s-5 pt-[max(16px,env(safe-area-inset-top))] text-white">
            <div className="grid h-[40px] w-[40px] flex-none place-items-center rounded-full bg-white text-[22px] text-wb-accent">
                <Icon name="rings" />
            </div>
            <div className="grid min-w-0 flex-1 gap-[1px]">
                <strong className="font-display text-[16px] font-bold tracking-[-.01em] text-white">{title}</strong>
                <small className="flex items-center gap-[6px] text-[12px] text-white/80">
                    <span className="h-[7px] w-[7px] rounded-full bg-wb-online shadow-[0_0_0_3px_var(--wb-online-ring)]" />
                    {status}
                </small>
            </div>
            {dev && (
                <span className="flex-none rounded-r-pill bg-amber-50 px-s-3 py-s-1 text-[11px] font-semibold uppercase tracking-[.08em] text-amber-700">
                    Dev mode
                </span>
            )}
        </div>
    );
}

export function Log({ children, logRef }) {
    return (
        <div
            ref={logRef}
            className="wb-log flex min-h-0 flex-1 flex-col gap-[18px] overflow-y-auto bg-wb-canvas px-s-3 py-s-4 md:px-[18px] md:py-s-5"
            aria-live="polite"
        >
            {children}
        </div>
    );
}

/* ---- Messages ---- */
function Avatar({ kind, children }) {
    const styles = kind === 'ai'
        ? 'bg-white text-wb-accent shadow-[0_0_0_1px_var(--wb-ai-line)] text-[15px]'
        : 'bg-blue-100 text-wb-accent font-display text-[10.5px] font-bold';
    return <span className={`grid h-[28px] w-[28px] flex-none place-items-center rounded-full ${styles}`}>{children}</span>;
}

function MessageTime({ at }) {
    const time = chatTime(at);
    return time ? <time dateTime={time.iso} title={time.title} className="px-[2px] text-[10px] leading-[1.4] text-wb-muted">{time.label}</time> : null;
}

export function AiBubble({ children, at }) {
    return (
        <div className="grid max-w-[86%] animate-wb-in gap-[6px]">
            <div className="flex items-center gap-s-2 font-display text-[12.5px] font-semibold text-wb-heading">
                <Avatar kind="ai"><Icon name="rings" /></Avatar>
                WedWorks Assistant
            </div>
            <div className="min-w-0 whitespace-pre-line break-words rounded-r-bubble rounded-tl-r-tail border border-wb-ai-line bg-wb-ai-bg px-s-4 py-s-3 text-[14.5px] leading-[1.5] text-wb-ai-text">
                {children}
            </div>
            <MessageTime at={at} />
        </div>
    );
}

export function UserBubble({ children, at }) {
    return (
        <div className="grid max-w-[86%] animate-wb-in justify-items-end gap-[6px] self-end">
            <div className="min-w-0 break-words rounded-r-bubble rounded-tr-r-tail bg-wb-user-bg px-s-4 py-s-3 text-[14.5px] leading-[1.5] text-wb-user-text shadow-sh-user">
                {children}
            </div>
            <MessageTime at={at} />
        </div>
    );
}

export function Typing() {
    return (
        <div className="inline-flex animate-wb-in gap-[5px] self-start rounded-r-bubble rounded-tl-r-tail border border-wb-ai-line bg-wb-ai-bg px-s-4 py-[14px]" role="status" aria-label="Assistant is typing">
            {[0, 1, 2].map((i) => (
                <i
                    key={i}
                    className="h-[6px] w-[6px] animate-wb-pulse rounded-full bg-wb-accent"
                    style={{ animationDelay: `${i * 0.15}s` }}
                />
            ))}
        </div>
    );
}

/* ---- Quick replies and chips ---- */
export function QuickReplyRow({ children }) {
    return <div className="flex flex-wrap gap-s-2">{children}</div>;
}

export function QuickReply({ tone = 'no', ...props }) {
    const tones = {
        yes: 'border-wb-accent bg-wb-accent text-white shadow-sh-blue hover:bg-wb-accent-hover',
        no: 'border-slate-300 bg-white text-wb-heading hover:border-wb-accent hover:text-wb-accent',
    };
    return (
        <button
            type="button"
            {...props}
            className={`min-h-[44px] min-w-[88px] rounded-r-pill border px-[22px] font-display text-[14px] font-semibold transition duration-fast active:scale-[.97] disabled:cursor-default disabled:opacity-40 disabled:shadow-none ${tones[tone]}`}
        />
    );
}

export function ChipRow({ children }) {
    return <div className="flex flex-wrap gap-[6px]">{children}</div>;
}

export function Chip({ selected, ...props }) {
    return (
        <button
            type="button"
            aria-pressed={!!selected}
            {...props}
            className={`min-h-[36px] rounded-r-pill border px-[14px] font-body text-[13px] font-medium transition duration-fast ${
                selected
                    ? 'border-wb-accent bg-wb-accent text-white'
                    : 'border-wb-ai-line bg-white text-wb-accent hover:border-wb-accent hover:bg-blue-50'
            }`}
        />
    );
}

/* ---- Input bar ---- */
export function InputZone({ children }) {
    return <div className="flex-none bg-white px-[14px] pb-s-4 pt-s-3">{children}</div>;
}

export function InputBar({ value, onChange, onSubmit, placeholder, type = 'text', inputMode, autoComplete, disabled, label, onAttach, attachDisabled }) {
    return (
        <form
            noValidate
            onSubmit={(e) => {
                e.preventDefault();
                if (disabled) return;
                onSubmit();
            }}
            className="flex items-center gap-s-1 rounded-r-pill border border-wb-line bg-slate-50 py-[5px] pl-[18px] pr-[5px] transition duration-fast focus-within:border-wb-accent focus-within:bg-white focus-within:shadow-[0_0_0_4px_var(--wb-accent-soft)]"
        >
            <input
                type={type}
                inputMode={inputMode}
                autoComplete={autoComplete}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder={placeholder}
                aria-label={label || placeholder}
                disabled={disabled}
                autoFocus
                className="h-[38px] min-w-0 flex-1 border-0 bg-transparent font-[inherit] text-[14.5px] text-wb-heading outline-none placeholder:text-wb-placeholder"
            />
            {onAttach && (
                <button
                    type="button"
                    onClick={onAttach}
                    disabled={attachDisabled}
                    aria-label="Attach a file"
                    className="grid h-[38px] w-[38px] place-items-center rounded-full border-0 bg-transparent text-[18px] text-wb-muted transition duration-fast hover:bg-blue-50 hover:text-wb-accent disabled:cursor-default disabled:opacity-40"
                >
                    <Icon name="attach" />
                </button>
            )}
            <button
                type="submit"
                aria-label="Send"
                disabled={disabled}
                className="grid h-[40px] w-[40px] flex-none place-items-center rounded-full bg-wb-accent text-[17px] text-white shadow-sh-blue transition duration-fast hover:bg-wb-accent-hover disabled:cursor-default disabled:bg-slate-200 disabled:text-slate-400 disabled:shadow-none"
            >
                <Icon name="send" />
            </button>
        </form>
    );
}

export function FieldError({ children }) {
    if (!children) return null;
    return (
        <p role="alert" className="mt-s-2 flex items-center gap-[6px] px-s-2 text-[12.5px] text-red-600">
            <Icon name="alert" />
            {children}
        </p>
    );
}

/* ---- Cards ---- */
export function Card({ children, center }) {
    return (
        <div className={`grid min-w-0 max-w-full md:max-w-[94%] animate-wb-in gap-s-3 rounded-r-card rounded-tl-r-tail border border-wb-line bg-white p-[18px] shadow-sh-sm ${center ? 'justify-items-center text-center' : ''}`}>
            {children}
        </div>
    );
}

export function CardIcon({ tone = 'info', name }) {
    const tones = {
        info: 'bg-blue-100 text-wb-accent',
        success: 'bg-green-50 text-green-700',
        warning: 'bg-amber-50 text-amber-700',
        danger: 'bg-red-50 text-red-600',
    };
    return (
        <div className={`grid h-[44px] w-[44px] place-items-center rounded-full text-[21px] ${tones[tone]}`}>
            <Icon name={name} />
        </div>
    );
}

export const CardTitle = ({ children }) => (
    <h3 className="font-display text-[18px] font-bold leading-[1.2] tracking-[-.01em] text-wb-heading">{children}</h3>
);

export const CardText = ({ children }) => (
    <p className="text-[14px] leading-[1.55] text-wb-muted [&_strong]:font-semibold [&_strong]:text-wb-heading">{children}</p>
);

export function Note({ icon = 'info', children }) {
    return (
        <div className="flex gap-[10px] rounded-[14px] border border-blue-200 bg-blue-50 px-[14px] py-s-3 text-left text-[13px] text-slate-700">
            <Icon name={icon} className="mt-[2px] text-[16px] text-wb-accent" />
            <div>{children}</div>
        </div>
    );
}

export function Alert({ tone = 'danger', title, children }) {
    const tones = {
        success: 'border-green-200 bg-green-50 text-green-700',
        warning: 'border-amber-200 bg-amber-50 text-amber-700',
        danger: 'border-red-200 bg-red-50 text-red-600',
        info: 'border-blue-200 bg-blue-50 text-blue-700',
    };
    return (
        <div role="alert" className={`flex items-start gap-[10px] rounded-[14px] border px-[14px] py-s-3 text-left text-[13.5px] ${tones[tone]}`}>
            <Icon name="alert" className="mt-[1px] text-[17px]" />
            <div>
                {title && <strong className="block font-semibold text-wb-heading">{title}</strong>}
                <span className="text-slate-700">{children}</span>
            </div>
        </div>
    );
}

export function Button({ variant = 'primary', block, ...props }) {
    const variants = {
        primary: 'bg-wb-accent text-white shadow-sh-blue hover:bg-wb-accent-hover',
        outline: 'border-slate-300 bg-white text-wb-heading hover:border-wb-accent hover:text-wb-accent',
        ghost: 'bg-transparent text-wb-accent',
    };
    return (
        <button
            type="button"
            {...props}
            className={`inline-flex min-h-[44px] items-center justify-center gap-s-2 rounded-r-pill border border-transparent px-s-5 font-display text-[14px] font-semibold transition duration-fast active:scale-[.98] disabled:cursor-default disabled:bg-slate-200 disabled:text-slate-500 disabled:shadow-none ${variants[variant]} ${block ? 'w-full' : ''}`}
        />
    );
}

export const Actions = ({ children }) => <div className="grid gap-s-2">{children}</div>;

/* ---- Review rows ---- */
export function ReviewRows({ children }) {
    return <div className="grid">{children}</div>;
}

export function ReviewRow({ label, value, onEdit }) {
    return (
        <div className="grid grid-cols-[minmax(0,1fr)_auto] gap-x-s-3 gap-y-[2px] border-b border-wb-line py-[11px] last:border-b-0">
            <div className="col-start-1 text-[11px] font-semibold uppercase tracking-[.08em] text-wb-muted">{label}</div>
            <div className="col-start-1 break-words text-[14.5px] text-wb-heading">{value}</div>
            {onEdit && (
                <button
                    type="button"
                    onClick={onEdit}
                    aria-label={`Edit ${label}`}
                    className="col-start-2 row-span-2 row-start-1 self-center rounded-[8px] border-0 bg-transparent px-s-1 py-[6px] font-body text-[13px] font-semibold text-wb-accent hover:bg-blue-50"
                >
                    Edit
                </button>
            )}
        </div>
    );
}

/* ---- Pills ---- */
/** `wrap` lets a long label wrap inside a narrow card (status cards on a phone) instead of poking out of it. */
export function Pill({ tone = 'info', wrap = false, children }) {
    const tones = {
        info: 'bg-blue-100 text-blue-700',
        success: 'bg-green-50 text-green-700',
        warning: 'bg-amber-50 text-amber-700',
        danger: 'bg-red-50 text-red-600',
        muted: 'bg-slate-100 text-slate-500',
    };
    return (
        <span className={`inline-flex max-w-full items-center gap-[6px] rounded-r-pill px-s-3 py-s-1 text-[12px] font-semibold ${wrap ? 'whitespace-normal' : 'whitespace-nowrap'} ${tones[tone]}`}>
            {children}
        </span>
    );
}

/* ---- Payment rows and amount ---- */
export const Divider = () => <div className="h-px w-full bg-wb-line" />;

export function PayRow({ label, children }) {
    return (
        <div className="flex justify-between gap-s-4 border-b border-wb-line py-[10px] text-[14px] last:border-b-0">
            <span className="text-wb-muted">{label}</span>
            <strong className="text-right font-semibold text-wb-heading">{children}</strong>
        </div>
    );
}

export function Amount({ value }) {
    return (
        <div className="font-display text-[38px] font-bold leading-none tracking-[-.03em] tabular-nums text-wb-heading">
            <small className="mr-[2px] text-[.55em] text-wb-accent">₱</small>
            {Number(value).toLocaleString('en-PH')}
        </div>
    );
}

/* ---- Link styled as a button, and the status card ---- */
export function ButtonLink({ variant = 'primary', block, className = '', ...props }) {
    const variants = {
        primary: 'bg-wb-accent text-white shadow-sh-blue hover:bg-wb-accent-hover',
        outline: 'border-slate-300 bg-white text-wb-heading hover:border-wb-accent hover:text-wb-accent',
        ghost: 'bg-transparent text-wb-accent',
    };
    return (
        <a
            {...props}
            className={`inline-flex min-h-[44px] items-center justify-center gap-s-2 rounded-r-pill border border-transparent px-s-5 font-display text-[14px] font-semibold transition duration-fast active:scale-[.98] ${variants[variant]} ${block ? 'w-full' : ''} ${className}`}
        />
    );
}

export function StatusCard({ title, children }) {
    return (
        <div className="grid min-w-0 grid-cols-[minmax(0,1fr)] justify-items-start gap-s-2 rounded-[var(--r-md)] border border-wb-line bg-white p-[14px]">
            <strong className="text-[13px] text-wb-heading">{title}</strong>
            {children}
        </div>
    );
}
