<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WebsiteLiveMail extends Mailable
{
    public const SUBJECT = 'Your website is live!';

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

        return new Content(view: 'emails.live', with: [
            'subject' => self::SUBJECT,
            'preheader' => "{$supplier->business_name} is live at {$this->order->website_url}",
            'supplier' => $supplier,
            'order' => $this->order,
            'firstName' => explode(' ', trim($supplier->name))[0],
            'liveOn' => $this->order->delivered_at->timezone(config('app.timezone'))->format('M j, Y · g:i A'),
        ]);
    }
}
