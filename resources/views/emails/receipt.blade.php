{{-- Payment receipt. Structure from docs/WedWorks_Email_Templates.html (#em-confirmed). --}}
<x-email.layout :subject="$subject" :preheader="$preheader">
<x-email.icon-circle tone="success" />
<x-email.spacer />
<x-email.heading>Payment confirmed!</x-email.heading>
<x-email.spacer />
<x-email.text>Thank you, {{ $firstName }}! We’ve received your payment and your website information for <strong style="color:#0f172a">{{ $supplier->business_name }}</strong>.</x-email.text>
<x-email.spacer />
<x-email.amount :value="number_format((float) $amount)" label="Amount paid" />
<x-email.spacer />
@php
    $confirmed = new Illuminate\Support\HtmlString(view('components.email.pill', ['tone' => 'success', 'inline' => true, 'slot' => 'Confirmed'])->render());
    $rows = [['Order reference', $order->reference], ['Business', $supplier->business_name], ['Package', 'Standard website'], ['Paid on', $paidOn]];
    if ($method) { $rows[] = ['Payment method', $method]; }
    $rows[] = ['Status', $confirmed];
@endphp
<x-email.rows title="Receipt" :rows="$rows" />
<x-email.spacer />
<x-email.steps :steps="['Our team reviews your website information.', 'We build your website after the event.', 'We target delivery within five days after the event, subject to complete requirements.']" />
<x-email.spacer />
<x-email.button :href="$link">View my website status</x-email.button>
</x-email.layout>
