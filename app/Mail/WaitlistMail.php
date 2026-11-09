<?php

namespace App\Mail;

use App\Models\Supplier;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WaitlistMail extends Mailable
{
    public const SUBJECT = 'We’ve received your request';

    public function __construct(public Supplier $supplier)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: self::SUBJECT);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.waitlist', with: [
            'subject' => self::SUBJECT,
            'preheader' => 'Thank you for registering. Please stay tuned for our update.',
            'firstName' => explode(' ', trim($this->supplier->name))[0],
        ]);
    }
}
