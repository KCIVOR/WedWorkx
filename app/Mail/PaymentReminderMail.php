<?php

namespace App\Mail;

use App\Models\Supplier;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PaymentReminderMail extends Mailable
{
    public const SUBJECT = 'Reminder: your invitation expires in 2 hours';

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
        $price = '₱'.number_format((float) $campaign->price);

        return new Content(view: 'emails.reminder', with: [
            'subject' => self::SUBJECT,
            'preheader' => "Complete your {$price} order before {$time} to keep slot {$this->supplier->slot_number}.",
            'firstName' => explode(' ', trim($this->supplier->name))[0],
            'total' => $campaign->slot_limit,
            'price' => $campaign->price,
            'deadline' => $deadline,
            'link' => url('/invite/'.$this->supplier->invite_token.'/my-website'),
        ]);
    }
}
