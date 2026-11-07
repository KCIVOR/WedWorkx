<?php

namespace App\Mail;

use App\Models\Supplier;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WaitlistMail extends Mailable
{
    public const SUBJECT = 'You’re on the waiting list';

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

        return new Content(view: 'emails.waitlist', with: [
            'subject' => self::SUBJECT,
            'preheader' => "All {$campaign->slot_limit} slots are taken. You’re #{$this->supplier->waitlist_number} in line.",
            'firstName' => explode(' ', trim($this->supplier->name))[0],
            'total' => $campaign->slot_limit,
        ]);
    }
}
