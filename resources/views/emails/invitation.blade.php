{{-- Invitation. Structure from docs/WedWorks_Email_Templates.html (#em-invite). --}}
<x-email.layout :subject="$subject" :preheader="$preheader">
<x-email.pill>Invited &middot; slot {{ $supplier->slot_number }} of {{ $total }}</x-email.pill>
<x-email.spacer />
<x-email.heading>Congratulations, {{ $firstName }}! You’re one of the first {{ $total }}.</x-email.heading>
<x-email.spacer />
<x-email.text>You’ve been selected to continue with WedWorx’s celebration of Kasalang Tagaytay’s 10th Anniversary. Tell us more about {{ $supplier->business_name }} and complete your website order.</x-email.text>
<x-email.spacer />
<x-email.slot-meter :taken="$supplier->slot_number" :total="$total" />
<x-email.spacer />
<x-email.rows :rows="[['Package', 'Standard website'], ['Amount', '₱'.number_format((float) $price)], ['Complete payment by', $deadline]]" />
<x-email.spacer />
<x-email.note>If payment isn’t completed by the deadline, your invitation expires. Slots are never reopened or handed to someone else.</x-email.note>
<x-email.spacer />
<x-email.button :href="$link">Continue to Website Chatbot</x-email.button>
</x-email.layout>
