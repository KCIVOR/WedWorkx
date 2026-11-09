<?php

namespace App\Mail;

use App\Models\Supplier;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Sent when Bot 1 is finished. Says only that the registration arrived; it never reveals an outcome. */
class RegistrationReceivedMail extends Mailable
{
    public const SUBJECT = 'We’ve received your registration';

    public function __construct(public Supplier $supplier)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: self::SUBJECT);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.registration-received', with: [
            'subject' => self::SUBJECT,
            'preheader' => 'Thank you for registering. We’ll keep in touch with updates.',
            'firstName' => explode(' ', trim($this->supplier->name))[0],
        ]);
    }
}
