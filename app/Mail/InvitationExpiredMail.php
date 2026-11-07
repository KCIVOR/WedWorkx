<?php

namespace App\Mail;

use App\Models\Supplier;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class InvitationExpiredMail extends Mailable
{
    public const SUBJECT = 'Your invitation has expired';

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

        return new Content(view: 'emails.expired', with: [
            'subject' => self::SUBJECT,
            'preheader' => "The {$campaign->invite_expiry_hours}-hour window for slot {$this->supplier->slot_number} has ended.",
            'firstName' => explode(' ', trim($this->supplier->name))[0],
            'hours' => $campaign->invite_expiry_hours,
            'link' => url('/invite/'.$this->supplier->invite_token.'/my-website'),
        ]);
    }
}
