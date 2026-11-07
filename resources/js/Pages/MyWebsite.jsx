import { useEffect, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import {
    AiBubble, Alert, Button, ButtonLink, Card, CardIcon, CardText, CardTitle, Header, Log, Note, Pill, ReviewRow, ReviewRows,
    Shell, StatusCard,
} from '../Components/wb';
import { SlotMeter } from '../Components/wb/forms';
import { ConfirmedCard, FailedCard, PaymentCard, startPayment } from '../Components/PaymentCards';
import { summarize } from '../lib/summarize';

const PAID = ['paid', 'building', 'delivered'];
const POLL_MS = 30000; // simple polling, no websockets

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

function LiveCard({ businessName, order }) {
    return (
        <Card>
            <CardIcon tone="success" name="check" />
            <CardTitle>Your website is live!</CardTitle>
            <CardText>
                The website for <strong>{businessName}</strong> is ready{order.deliveredOn ? <> and went live on <span className="tabular-nums">{order.deliveredOn}</span></> : null}.
            </CardText>
            <div className="break-all text-[14px] font-semibold text-wb-heading">{order.websiteUrl}</div>
            <ButtonLink block href={order.websiteUrl} target="_blank" rel="noopener noreferrer">Visit my website</ButtonLink>
        </Card>
    );
}

/** Production progress: 1/6 to 6/6, amber while in progress and green when done. */
function ProgressCard({ order, delivered }) {
    const stage = order.stage;
    const text = delivered
        ? 'All six steps are done and your website is live.'
        : stage === 0
            ? 'Your payment is confirmed. Our team will start building your website soon.'
            : stage === 6
                ? 'Final checks are done. Your website will be delivered very soon.'
                : `Step ${stage} of 6 is done. We are working on the next one.`;
    return (
        <Card>
            <CardTitle>Website progress</CardTitle>
            <div>
                {delivered
                    ? <Pill tone="success">Complete (6/6)</Pill>
                    : stage === 0
                        ? <Pill tone="muted">Waiting to start (0/6)</Pill>
                        : <Pill tone="warning">In progress ({stage}/6)</Pill>}
            </div>
            <SlotMeter taken={stage} total={6} label={`${stage} of 6 steps done`} />
            <CardText>{text}</CardText>
        </Card>
    );
}

function StatusGrid({ p }) {
    const paid = PAID.includes(p.status);
    const delivered = p.status === 'delivered';
    const expired = p.status === 'expired';
    const stage = p.order?.stage ?? 0;

    const invitation = expired ? ['danger', 'Expired'] : paid ? ['success', 'Accepted'] : ['info', 'Invited'];
    const payment = paid ? ['success', 'Paid']
        : p.status === 'payment_pending' ? ['warning', 'Pending']
            : p.payment.lastFailed ? ['danger', 'Failed, retry needed']
                : ['muted', 'Not yet paid'];
    const website = p.detailsSubmitted ? ['success', paid ? 'Submitted · locked' : 'Submitted'] : ['muted', 'Not started'];
    const production = delivered ? ['success', 'Complete (6/6)']
        : paid && stage > 0 ? ['warning', `In progress (${stage}/6)`]
            : paid ? ['muted', 'Waiting to start']
                : ['muted', 'Not started'];
    const delivery = delivered ? ['success', 'Live'] : ['muted', 'Not yet'];

    const items = [
        ['Registration', ['success', 'Complete']],
        ['Invitation', invitation],
        ['Payment', payment],
        ['Website information', website],
        ['Production', production],
        ['Delivery', delivery],
    ];

    return (
        <div className="grid max-w-[94%] grid-cols-2 gap-s-3">
            {items.map(([title, [tone, label]]) => (
                <StatusCard key={title} title={title}><Pill tone={tone} wrap>{label}</Pill></StatusCard>
            ))}
        </div>
    );
}

function BeforePayment({ p }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    async function pay() {
        setBusy(true);
        setError('');
        const problem = await startPayment(p.token);
        if (problem) {
            setError(problem.message);
            setBusy(false);
            if (problem.reload) router.reload();
        }
    }

    if (p.status === 'invited') {
        return (
            <Card>
                <CardIcon tone="info" name="mail" />
                <CardTitle>Finish your website details</CardTitle>
                <CardText>
                    You&apos;re invited, {p.firstName}! Tell us about <strong>{p.businessName}</strong> first, then you can pay.
                    {p.deadline && <> Your invitation ends on <strong className="tabular-nums">{p.deadline}</strong>.</>}
                </CardText>
                <ButtonLink block href={`/invite/${p.token}`}>Continue to website details</ButtonLink>
            </Card>
        );
    }

    if (p.payment.lastFailed) {
        return <FailedCard busy={busy} error={error} onRetry={pay} />;
    }

    return (
        <>
            <PaymentCard
                businessName={p.businessName}
                price={p.payment.price}
                pending={p.status === 'payment_pending'}
                expiresAt={p.expiresAt}
                serverNow={p.serverNow}
                busy={busy}
                error={error}
                onPay={pay}
            />
            {p.canEditDetails && (
                <div className="max-w-[94%]"><ButtonLink block variant="outline" href={`/invite/${p.token}`}>Review or change my answers</ButtonLink></div>
            )}
        </>
    );
}

function LockedAnswers({ review }) {
    return (
        <Card>
            <CardTitle>Your website information</CardTitle>
            <Note icon="info">Your details are locked now that your order is paid, so they can&apos;t be edited.</Note>
            <ReviewRows>
                {review.questions.map((q) => (
                    <ReviewRow key={q.key} label={q.label} value={summarize(q, review.answers, review.uploads) || '—'} />
                ))}
            </ReviewRows>
        </Card>
    );
}


/** Dev mode only: stand-ins for Hermes and the scheduler, so the journey can be finished by hand. */
function DevPanel({ token, status, order, expired }) {
    const { flash } = usePage().props;
    const [busy, setBusy] = useState(false);
    const act = (what) => {
        setBusy(true);
        router.post(`/dev/invite/${token}/${what}`, {}, { preserveScroll: true, onFinish: () => setBusy(false) });
    };
    const paid = PAID.includes(status);
    const stage = order?.stage ?? 0;
    const advanceLabel = status === 'delivered' ? 'Delivered' : stage >= 6 ? 'Deliver the website' : `Advance production to stage ${stage + 1}`;

    return (
        <Card>
            <div><Pill tone="warning">Dev mode</Pill></div>
            <CardTitle>Dev tools</CardTitle>
            <CardText>Stand-ins for the outside world. They use the same rules as the real Hermes API and scheduler.</CardText>
            {flash?.dev_message && <Alert tone="warning">{flash.dev_message}</Alert>}
            <div className="grid gap-s-2">
                {paid && <Button block disabled={busy || status === 'delivered'} onClick={() => act('advance')}>{advanceLabel}</Button>}
                {!paid && !expired && <Button block variant="outline" disabled={busy} onClick={() => act('remind')}>Send the 2-hour reminder now</Button>}
                {!paid && !expired && <Button block variant="outline" disabled={busy} onClick={() => act('expire')}>Expire this invitation now</Button>}
                {!paid && expired && <CardText>Expired. Register again to test another supplier.</CardText>}
            </div>
        </Card>
    );
}

export default function MyWebsite(props) {
    const { firstName, businessName, status, order, review } = props;
    const paid = PAID.includes(status);
    const done = status === 'delivered' || status === 'expired';

    // Refresh the status by itself every 30 seconds until nothing can change any more.
    useEffect(() => {
        if (done) return undefined;
        const t = setInterval(() => router.reload({ preserveScroll: true }), POLL_MS);
        return () => clearInterval(t);
    }, [done]);

    return (
        <>
            <Head title="My Website" />
            <main className="flex min-h-dvh justify-center p-s-4 md:items-center md:p-s-8">
                <div className="h-[calc(100dvh-32px)] w-full max-w-[600px] md:h-[calc(100dvh-64px)] md:max-h-[760px]">
                    <Shell>
                        <Header title="My Website" status={businessName} />
                        <Log>
                            <AiBubble>
                                {status === 'expired'
                                    ? `Hi ${firstName}. Here is where ${businessName} stands.`
                                    : `Hi ${firstName}! Here is where your website for ${businessName} stands.`}
                            </AiBubble>

                            {status === 'expired' && <ExpiredCard firstName={firstName} businessName={businessName} />}
                            {!paid && status !== 'expired' && <BeforePayment p={props} />}
                            {paid && <ConfirmedCard firstName={firstName} businessName={businessName} order={order} status={status} />}
                            {paid && <ProgressCard order={order} delivered={status === 'delivered'} />}
                            {status === 'delivered' && <LiveCard businessName={businessName} order={order} />}

                            <StatusGrid p={props} />

                            {paid && review && <LockedAnswers review={review} />}
                            {props.dev && <DevPanel token={props.token} status={status} order={order} expired={status === 'expired'} />}
                        </Log>
                    </Shell>
                </div>
            </main>
        </>
    );
}
