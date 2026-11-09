import { Head } from '@inertiajs/react';

/**
 * T1 proof page. Every class below is a token from tailwind.config.js, which maps
 * docs/WedWorks_Chatbot_Design_System.html one to one. Not a real screen.
 */

function Header() {
    return (
        <div className="relative z-[1] flex flex-none items-center gap-s-3 rounded-b-r-shell bg-wb-accent px-s-4 pb-s-5 pt-s-4 text-white">
            <div className="grid min-w-0 flex-1 gap-[1px]">
                <strong className="font-display text-[16px] font-bold tracking-[-.01em] text-white">WedWorx Assistant</strong>
                <small className="flex items-center gap-[6px] text-[12px] text-white/80">
                    <span className="h-[7px] w-[7px] rounded-full bg-wb-online shadow-[0_0_0_3px_var(--wb-online-ring)]" />
                    Online
                </small>
            </div>
        </div>
    );
}

function AiBubble({ children }) {
    return (
        <div className="grid max-w-[86%] animate-wb-in gap-[6px]">
            <div className="min-w-0 break-words rounded-r-bubble rounded-tl-r-tail border border-wb-ai-line bg-wb-ai-bg px-s-4 py-s-3 text-[14.5px] leading-[1.5] text-wb-ai-text">
                {children}
            </div>
            <span className="px-s-1 text-[11px] tabular-nums text-wb-muted">9:41 AM</span>
        </div>
    );
}

function UserBubble({ children }) {
    return (
        <div className="grid max-w-[86%] animate-wb-in justify-items-end gap-[6px] self-end">
            <div className="min-w-0 break-words rounded-r-bubble rounded-tr-r-tail bg-wb-user-bg px-s-4 py-s-3 text-[14.5px] leading-[1.5] text-wb-user-text shadow-sh-user">
                {children}
            </div>
            <span className="px-s-1 text-[11px] tabular-nums text-wb-muted">9:42 AM</span>
        </div>
    );
}

function PrimaryButton({ children }) {
    return (
        <button
            type="button"
            className="inline-flex min-h-[44px] items-center justify-center gap-s-2 rounded-r-pill border border-transparent bg-wb-accent px-s-5 font-display text-[14px] font-semibold text-white shadow-sh-blue transition duration-fast hover:bg-wb-accent-hover active:scale-[.98]"
        >
            {children}
        </button>
    );
}

function Pill({ tone, children }) {
    const tones = {
        info: 'bg-blue-100 text-blue-700',
        success: 'bg-green-50 text-green-700',
        warning: 'bg-amber-50 text-amber-700',
        danger: 'bg-red-50 text-red-600',
        muted: 'bg-slate-100 text-slate-500',
    };
    return (
        <span className={`inline-flex items-center gap-[6px] whitespace-nowrap rounded-r-pill px-s-3 py-s-1 text-[12px] font-semibold ${tones[tone]}`}>
            {children}
        </span>
    );
}

function Card() {
    return (
        <div className="grid min-w-0 max-w-[94%] gap-s-3 rounded-r-card rounded-tl-r-tail border border-wb-line bg-white p-[18px] shadow-sh-sm">
            <div className="text-[11px] font-semibold uppercase tracking-[.1em] text-wb-accent">Your slot</div>
            <h3 className="font-display text-[18px] font-bold leading-[1.2] tracking-[-.01em] text-wb-heading">Thank you, check your email.</h3>
            <p className="text-[14px] leading-[1.55] text-wb-muted">
                We sent the next steps to <strong className="font-semibold text-wb-heading">your inbox</strong>.
            </p>
            <div className="flex flex-wrap gap-s-2">
                <Pill tone="info">Invited</Pill>
                <Pill tone="warning">In progress</Pill>
                <Pill tone="success">Paid</Pill>
                <Pill tone="danger">Expired</Pill>
                <Pill tone="muted">Waitlisted</Pill>
            </div>
            <PrimaryButton>Continue</PrimaryButton>
        </div>
    );
}

export default function DesignCheck() {
    return (
        <>
            <Head title="Design check" />
            <main className="mx-auto flex min-h-screen w-full max-w-[400px] items-stretch p-s-4">
                <div className="flex w-full flex-col overflow-hidden rounded-r-shell border border-wb-line bg-wb-surface font-body text-wb-text shadow-sh-md">
                    <Header />
                    <div className="flex flex-1 flex-col gap-[18px] bg-wb-canvas px-[18px] py-s-5">
                        <AiBubble>Hi! I&apos;m the WedWorx Assistant. What&apos;s your name?</AiBubble>
                        <UserBubble>Maria Santos</UserBubble>
                        <Card />
                    </div>
                </div>
            </main>
        </>
    );
}
