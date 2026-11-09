{{-- Registration received (sent to every supplier when they finish Bot 1). Deliberately says nothing about slots, winners or a waiting list: the admin picks the winners later. --}}
<x-email.layout :subject="$subject" :preheader="$preheader">
<x-email.pill>Registration received</x-email.pill>
<x-email.spacer />
<x-email.heading>We’ve received your registration</x-email.heading>
<x-email.spacer />
<x-email.text>Hi {{ $firstName }}, thank you for registering {{ $supplier->business_name }} for WedWorx’s celebration of Kasalang Tagaytay’s 10th Anniversary. We’ve received your registration and our team will go through it.</x-email.text>
<x-email.spacer />
<x-email.text>There’s nothing else you need to do for now. We’ll keep in touch and update you by email as soon as we have news.</x-email.text>
</x-email.layout>
