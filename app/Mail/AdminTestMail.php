<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The "send me a test email" message on the admin settings page. Plain, no supplier data. */
class AdminTestMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'WedWorks test email');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>This is a test from the WedWorks admin.</p><p>If you can read it, your email settings work.</p>');
    }
}
