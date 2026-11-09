{{-- Waiting list. Structure from docs/WedWorks_Email_Templates.html (#em-waitlist), wording changed for decision D9. --}}
<x-email.layout :subject="$subject" :preheader="$preheader">
<x-email.pill tone="warning">Waiting list &middot; #{{ $supplier->waitlist_number }}</x-email.pill>
<x-email.spacer />
<x-email.heading>You’re on the waiting list</x-email.heading>
<x-email.spacer />
<x-email.text>Hi {{ $firstName }}, thank you for registering {{ $supplier->business_name }}. All {{ $total }} website slots have been taken, and you’re number {{ $supplier->waitlist_number }} on the waiting list.</x-email.text>
<x-email.spacer />
<x-email.text>We’re sorry to say that slots do not reopen, so we can’t offer you a spot in this campaign. We truly appreciate your interest, and we’ll be glad to reach out about future WedWorx offers.</x-email.text>
<x-email.spacer />
<x-email.slot-meter :taken="$total" :total="$total" />
</x-email.layout>
