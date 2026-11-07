<?php

namespace App\Mail;

use App\Models\Supplier;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PaymentFailedMail extends Mailable
{
    public const SUBJECT = 'Your payment didn’t go through';

    public function __construct(public Supplier $supplier)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: self::SUBJECT);
    }

    public function content(): Content
    {
        $campaign = $this->supplier->campaign;
        $deadline = $this->supplier->invite_expires_at->timezone(config('app.timezone'))->format('M j, Y · g:i A');
        $time = $this->supplier->invite_expires_at->timezone(config('app.timezone'))->format('g:i A');

        return new Content(view: 'emails.payment-failed', with: [
            'subject' => self::SUBJECT,
            'preheader' => "Your invitation is still valid. You can try again before {$time}.",
            'firstName' => explode(' ', trim($this->supplier->name))[0],
            'price' => $campaign->price,
            'total' => $campaign->slot_limit,
            'deadline' => $deadline,
            'link' => url('/invite/'.$this->supplier->invite_token.'/my-website'),
        ]);
    }
}
