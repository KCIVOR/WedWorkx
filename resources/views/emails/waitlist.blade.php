{{-- Registration received (sent to suppliers without an invitation). Structure from docs/WedWorks_Email_Templates.html (#em-waitlist). Deliberately never mentions a waiting list or slot numbers (decision D9). --}}
<x-email.layout :subject="$subject" :preheader="$preheader">
<x-email.pill>Request received</x-email.pill>
<x-email.spacer />
<x-email.heading>We’ve received your request</x-email.heading>
<x-email.spacer />
<x-email.text>Hi {{ $firstName }}, thank you for registering {{ $supplier->business_name }} for the WedWorx 10-10-10 Campaign. We’ve received your details and our team is reviewing them.</x-email.text>
<x-email.spacer />
<x-email.text>There’s nothing else you need to do for now. Please keep an eye on your inbox, as we’ll email you here as soon as we have an update.</x-email.text>
</x-email.layout>
