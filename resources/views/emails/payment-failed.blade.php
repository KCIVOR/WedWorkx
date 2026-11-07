{{-- Payment unsuccessful. Structure from docs/WedWorks_Email_Templates.html (#em-failed). --}}
<x-email.layout :subject="$subject" :preheader="$preheader">
<x-email.icon-circle tone="danger" />
<x-email.spacer />
<x-email.heading>Your payment didn’t go through</x-email.heading>
<x-email.spacer />
<x-email.text>Hi {{ $firstName }}, we couldn’t complete your ₱{{ number_format((float) $price) }} payment. You haven’t been charged, and your invitation is still valid.</x-email.text>
<x-email.spacer />
@php
    $failed = new Illuminate\Support\HtmlString(view('components.email.pill', ['tone' => 'danger', 'inline' => true, 'slot' => 'Failed, retry needed'])->render());
@endphp
<x-email.rows :rows="[['Slot', $supplier->slot_number.' of '.$total], ['Try again before', $deadline], ['Payment status', $failed]]" />
<x-email.spacer />
<x-email.button :href="$link">Try again</x-email.button>
</x-email.layout>
