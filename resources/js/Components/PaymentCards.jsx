import { useEffect, useState } from 'react';
import { Actions, Alert, Amount, Button, Card, CardIcon, CardText, CardTitle, Divider, Icon, PayRow, Pill } from './wb';

/* Payment cards from the design system: Payment, Saved for later, Failed, Confirmed. */

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

/** Asks the server for the HitPay link (the live one, or a fresh one) and sends the browser there. */
export async function startPayment(token) {
    const res = await fetch(`/invite/${token}/pay`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
    });
    let json = null;
    try { json = await res.json(); } catch { /* empty body */ }
    if (res.ok && json?.url) {
        window.location.href = json.url;
        return null;
    }
    if (res.status === 403) return { message: 'This invitation is no longer open for payment.', reload: true };
    return { message: json?.message ?? 'We could not start the payment right now. Please try again in a moment.' };
}

function TimeLeft({ expiresAt, serverNow }) {
    const [offset] = useState(() => Date.parse(serverNow) - Date.now());
    const calc = () => Math.max(0, Date.parse(expiresAt) - (Date.now() + offset));
    const [ms, setMs] = useState(calc);
    useEffect(() => {
        const t = setInterval(() => setMs(calc()), 15000);
        return () => clearInterval(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [expiresAt]);
    const h = Math.floor(ms / 3600000);
    const m = Math.floor((ms % 3600000) / 60000);
    return <span className="tabular-nums">{h}h {String(m).padStart(2, '0')}m</span>;
}

export function PaymentCard({ businessName, price, pending, expiresAt, serverNow, busy, error, onPay, onLater }) {
    return (
        <Card center>
            <CardTitle>Confirm your order</CardTitle>
            <CardText>Kasalang Tagaytay 10th Anniversary</CardText>
            <Amount value={price} />
            <CardText>One-time website package</CardText>
            <Divider />
            <div className="grid w-full">
                <PayRow label="Business">{businessName}</PayRow>
                <PayRow label="Package">Standard website</PayRow>
                <PayRow label="Payment status">
                    {pending ? <Pill tone="warning">Payment pending</Pill> : <Pill tone="warning">Not yet paid</Pill>}
                </PayRow>
                <PayRow label="Invitation expires in"><TimeLeft expiresAt={expiresAt} serverNow={serverNow} /></PayRow>
            </div>
            {pending && (
                <CardText>
                    If you just paid, we&apos;re waiting for HitPay to confirm it. This page updates by itself, so you don&apos;t need to pay again.
                </CardText>
            )}
            {error && <Alert title="Payment not started">{error}</Alert>}
            <div className="grid w-full gap-s-2">
                <Button block disabled={busy} onClick={onPay}>
                    {busy ? 'Opening HitPay…' : <>{pending ? 'Resume payment' : `Pay ₱${Number(price).toLocaleString('en-PH')}`} <Icon name="send" /></>}
                </Button>
                {onLater && <Button variant="ghost" block disabled={busy} onClick={onLater}>Pay later</Button>}
            </div>
        </Card>
    );
}

export function SavedLaterCard({ onResume, busy }) {
    return (
        <Card>
            <CardIcon tone="info" name="pause" />
            <CardTitle>Saved for later</CardTitle>
            <CardText>
                No problem. Your website information is saved. You can come back and pay any time before your invitation expires,
                using the link in your email.
            </CardText>
            <Actions>
                <Button block disabled={busy} onClick={onResume}>Resume payment</Button>
            </Actions>
        </Card>
    );
}

export function FailedCard({ onRetry, busy, error }) {
    return (
        <Card>
            <CardIcon tone="danger" name="x" />
            <CardTitle>Payment unsuccessful</CardTitle>
            <CardText>
                Your payment could not be completed. You haven&apos;t been charged, and your invitation is still valid, so you can try again before it expires.
            </CardText>
            {error && <Alert title="Payment not started">{error}</Alert>}
            <Actions>
                <Button block disabled={busy} onClick={onRetry}><Icon name="retry" /> Try again</Button>
            </Actions>
        </Card>
    );
}

const ORDER_STATUS = {
    paid: { tone: 'success', label: 'Confirmed' },
    building: { tone: 'warning', label: 'Building' },
    delivered: { tone: 'success', label: 'Delivered' },
};

export function ConfirmedCard({ firstName, businessName, order, status }) {
    const s = ORDER_STATUS[status] ?? ORDER_STATUS.paid;
    return (
        <Card>
            <CardIcon tone="success" name="check" />
            <CardTitle>Payment confirmed!</CardTitle>
            <CardText>
                Thank you, {firstName}! We&apos;ve received your ₱{Number(order.amount).toLocaleString('en-PH')} payment and your website
                information for <strong>{businessName}</strong>.
            </CardText>
            <div className="grid">
                <PayRow label="Order reference">{order.reference}</PayRow>
                <PayRow label="Amount paid">₱{Number(order.amount).toLocaleString('en-PH')}</PayRow>
                <PayRow label="Paid on"><span className="tabular-nums">{order.paidOn}</span></PayRow>
                <PayRow label="Status"><Pill tone={s.tone}>{s.label}</Pill></PayRow>
            </div>
            <CardText>Your details are now locked. We&apos;ll email you when your website is live.</CardText>
        </Card>
    );
}
