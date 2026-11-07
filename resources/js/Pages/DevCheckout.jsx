import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    Actions, Amount, Button, ButtonLink, Card, CardText, CardTitle, Divider, Header, Log, Note, PayRow, Pill, Shell,
} from '../Components/wb';

/** Dev mode only: stands in for HitPay's hosted checkout page. */
export default function DevCheckout({ requestId, businessName, amount, status, myWebsite }) {
    const [busy, setBusy] = useState(false);
    const settle = (outcome) => {
        setBusy(true);
        router.post(`/dev/checkout/${requestId}/${outcome}`, {}, { onFinish: () => setBusy(false) });
    };
    const open = status === 'pending';

    return (
        <>
            <Head title="Simulated checkout" />
            <main className="flex min-h-dvh justify-center p-s-4 md:items-center md:p-s-8">
                <div className="h-[calc(100dvh-32px)] w-full max-w-[600px] md:h-[calc(100dvh-64px)] md:max-h-[760px]">
                    <Shell>
                        <Header title="Simulated checkout" status="Stands in for HitPay" />
                        <Log>
                            <Card center>
                                <Pill tone="warning">Dev mode: no real payment</Pill>
                                <CardTitle>Pay ₱{Number(amount).toLocaleString('en-PH')}?</CardTitle>
                                <Amount value={amount} />
                                <Divider />
                                <div className="grid w-full">
                                    <PayRow label="Business">{businessName}</PayRow>
                                    <PayRow label="Request"><span className="break-all font-mono text-[12px]">{requestId}</span></PayRow>
                                    <PayRow label="Status"><Pill tone={open ? 'warning' : status === 'completed' ? 'success' : 'muted'}>{status}</Pill></PayRow>
                                </div>
                                <Note>
                                    This page replaces HitPay while dev mode is on. The buttons below do exactly what HitPay&apos;s signed webhook does, so
                                    the order, emails and rules all behave as in real life. Turn dev mode off to use the real HitPay.
                                </Note>
                                {open ? (
                                    <div className="grid w-full gap-s-2">
                                        <Button block disabled={busy} onClick={() => settle('completed')}>Simulate a successful payment</Button>
                                        <Button block variant="outline" disabled={busy} onClick={() => settle('failed')}>Simulate a failed payment</Button>
                                    </div>
                                ) : (
                                    <CardText>This payment is already {status}.</CardText>
                                )}
                                <Actions>
                                    <ButtonLink block variant="ghost" href={myWebsite}>Back to My Website</ButtonLink>
                                </Actions>
                            </Card>
                        </Log>
                    </Shell>
                </div>
            </main>
        </>
    );
}
