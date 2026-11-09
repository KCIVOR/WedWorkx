<?php

namespace App\Mail;

use App\Models\Supplier;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class InvitationMail extends Mailable
{
    public const SUBJECT = 'You’re in! Your Kasalang Tagaytay 10th Anniversary website invitation';

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

        return new Content(view: 'emails.invitation', with: [
            'subject' => self::SUBJECT,
            'preheader' => "Slot {$this->supplier->slot_number} of {$campaign->slot_limit} is yours. Complete your order by {$deadline}.",
            'firstName' => explode(' ', trim($this->supplier->name))[0],
            'total' => $campaign->slot_limit,
            'price' => $campaign->price,
            'deadline' => $deadline,
            'link' => url('/invite/'.$this->supplier->invite_token),
        ]);
    }
}
