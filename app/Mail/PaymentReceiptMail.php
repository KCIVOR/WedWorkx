<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PaymentReceiptMail extends Mailable
{
    public const SUBJECT = 'Payment confirmed. Your website order is in';

    public function __construct(public Order $order)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: self::SUBJECT);
    }

    public function content(): Content
    {
        $supplier = $this->order->supplier;
        $payment = $supplier->payments()->where('status', 'completed')->latest('id')->first();
        $amount = $payment?->amount ?? $supplier->campaign->price;
        $method = data_get($payment?->webhook_payload, 'payment_type') ?: data_get($payment?->webhook_payload, 'payment_method');

        return new Content(view: 'emails.receipt', with: [
            'subject' => self::SUBJECT,
            'preheader' => "Receipt {$this->order->reference} · ₱".number_format((float) $amount)." for {$supplier->business_name}.",
            'supplier' => $supplier,
            'order' => $this->order,
            'firstName' => explode(' ', trim($supplier->name))[0],
            'amount' => $amount,
            'paidOn' => $this->order->paid_at->timezone(config('app.timezone'))->format('M j, Y · g:i A'),
            'method' => is_string($method) ? ucfirst(str_replace('_', ' ', $method)) : null,
            'link' => url('/invite/'.$supplier->invite_token.'/my-website'),
        ]);
    }
}
