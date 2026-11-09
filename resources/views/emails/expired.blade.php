{{-- Invitation expired. Structure from docs/WedWorks_Email_Templates.html (#em-expired), wording changed for decision D9. --}}
<x-email.layout :subject="$subject" :preheader="$preheader">
<x-email.pill tone="danger">Invitation expired</x-email.pill>
<x-email.spacer />
<x-email.heading>Your invitation has expired</x-email.heading>
<x-email.spacer />
<x-email.text>Hi {{ $firstName }}, your {{ $hours }}-hour invitation for {{ $supplier->business_name }} ended before payment was completed, so slot {{ $supplier->slot_number }} is now closed.</x-email.text>
<x-email.spacer />
<x-email.text :muted="true">Slots for this offer do not reopen, so we’re unable to extend it. Thank you for your interest, and we hope to work with you on a future WedWorx offer.</x-email.text>
<x-email.spacer />
<x-email.button :href="$link" variant="outline">Go to my website</x-email.button>
</x-email.layout>
