import { useEffect, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { AiBubble, Card, CardIcon, CardText, CardTitle, Header, Log, Pill, Shell } from '../Components/wb';
import WebsiteChat from '../Components/WebsiteChat';
import { ConfirmedCard, PaymentCard, startPayment } from '../Components/PaymentCards';

// "My Website" for paid orders comes in T8; until then paid suppliers see their confirmation.

function ExpiredCard({ firstName, businessName }) {
    return (
        <Card>
            <CardIcon tone="danger" name="clock" />
            <CardTitle>Your invitation has expired</CardTitle>
            <CardText>
                Hi {firstName}, your invitation for <strong>{businessName}</strong> ended before payment was completed, so this
                slot is now closed. Slots in this campaign do not reopen. Thank you for your interest!
            </CardText>
            <div><Pill tone="danger">Invitation expired</Pill></div>
        </Card>
    );
}

/** payment_pending: a link exists. Resume it, and refresh by itself until HitPay's webhook confirms. */
function PendingPayment({ props }) {
    const { firstName, businessName, payment, expiresAt, serverNow, token } = props;
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        const t = setInterval(() => router.reload({ preserveScroll: true }), 8000);
        return () => clearInterval(t);
    }, []);

    async function resume() {
        setBusy(true);
        setError('');
        const problem = await startPayment(token);
        if (problem) {
            setError(problem.message);
            setBusy(false);
            if (problem.reload) router.reload();
        }
    }

    return (
        <>
            <AiBubble>Hi {firstName}! Welcome back. Your website information is saved, and the last step is payment.</AiBubble>
            <PaymentCard
                businessName={businessName}
                price={payment.price}
                pending
                expiresAt={expiresAt}
                serverNow={serverNow}
                busy={busy}
                error={error}
                onPay={resume}
            />
        </>
    );
}

function StatusCard({ props }) {
    const { firstName, businessName, status, deadline, order } = props;

    if (order && ['paid', 'building', 'delivered'].includes(status)) {
        return (
            <>
                <AiBubble>Hi {firstName}! Welcome back.</AiBubble>
                <ConfirmedCard firstName={firstName} businessName={businessName} order={order} status={status} />
            </>
        );
    }

    return (
        <>
            <AiBubble>Hi {firstName}! Welcome back.</AiBubble>
            <Card>
                <CardIcon tone="info" name="mail" />
                <CardTitle>{businessName}</CardTitle>
                <div><Pill tone="muted">{status.replace('_', ' ')}</Pill></div>
                {deadline && <CardText>Invitation deadline: <strong className="tabular-nums">{deadline}</strong></CardText>}
            </Card>
        </>
    );
}

export default function Invite(props) {
    const { firstName, businessName, status, chat, payment } = props;
    const expired = status === 'expired';

    let body;
    if (chat) {
        body = <WebsiteChat chat={chat} payment={payment} firstName={firstName} businessName={businessName} />;
    } else {
        body = (
            <Shell>
                <Header title="WedWorks Assistant" />
                <Log>
                    {expired && <ExpiredCard firstName={firstName} businessName={businessName} />}
                    {!expired && status === 'payment_pending' && <PendingPayment props={props} />}
                    {!expired && status !== 'payment_pending' && <StatusCard props={props} />}
                </Log>
            </Shell>
        );
    }

    return (
        <>
            <Head title={expired ? 'Invitation expired' : 'Your invitation'} />
            <main className="flex min-h-dvh justify-center p-s-4 md:items-center md:p-s-8">
                <div className="h-[calc(100dvh-32px)] w-full max-w-[600px] md:h-[calc(100dvh-64px)] md:max-h-[760px]">{body}</div>
            </main>
        </>
    );
}
