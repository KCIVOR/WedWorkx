{{-- Website live. The templates file has no "live" email, so this is composed from the existing email components. --}}
<x-email.layout :subject="$subject" :preheader="$preheader">
<x-email.icon-circle tone="success" />
<x-email.spacer />
<x-email.heading>Your website is live!</x-email.heading>
<x-email.spacer />
<x-email.text>Hi {{ $firstName }}, great news. The website for <strong style="color:#0f172a">{{ $supplier->business_name }}</strong> is ready, and couples can find it now.</x-email.text>
<x-email.spacer />
@php
    $live = new Illuminate\Support\HtmlString(view('components.email.pill', ['tone' => 'success', 'inline' => true, 'slot' => 'Live'])->render());
@endphp
<x-email.rows :rows="[['Order reference', $order->reference], ['Your website', $order->website_url], ['Went live', $liveOn], ['Status', $live]]" />
<x-email.spacer />
<x-email.button :href="$order->website_url">Visit my website</x-email.button>
<x-email.spacer />
<x-email.text :muted="true">You can see your order any time on your My Website page. If you would like anything changed, just reply to this email.</x-email.text>
</x-email.layout>
