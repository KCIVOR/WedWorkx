{{-- Payment reminder. Structure from docs/WedWorks_Email_Templates.html (#em-reminder). --}}
<x-email.layout :subject="$subject" :preheader="$preheader">
<x-email.pill tone="warning">Expires in about 2 hours</x-email.pill>
<x-email.spacer />
<x-email.heading>Your slot is still waiting for you</x-email.heading>
<x-email.spacer />
<x-email.text>Hi {{ $firstName }}, your invitation for {{ $supplier->business_name }} ends soon. Complete your order before the deadline to keep your slot.</x-email.text>
<x-email.spacer />
<x-email.amount :value="number_format((float) $price)" />
<x-email.spacer />
@php
    $notPaid = new Illuminate\Support\HtmlString(view('components.email.pill', ['tone' => 'warning', 'inline' => true, 'slot' => 'Not yet paid'])->render());
@endphp
<x-email.rows :rows="[['Slot', $supplier->slot_number.' of '.$total], ['Deadline', $deadline], ['Payment status', $notPaid]]" />
<x-email.spacer />
<x-email.button :href="$link">Pay ₱{{ number_format((float) $price) }}</x-email.button>
</x-email.layout>
