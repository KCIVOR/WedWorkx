{{-- variant: primary (blue pill) or outline (white pill), from the email templates file. --}}
@props(['href', 'variant' => 'primary'])
@php
    $outline = $variant === 'outline';
    $cell = $outline ? 'background:#ffffff;border-radius:999px;border:1px solid #cbd5e1' : 'background:#2f5bea;border-radius:999px';
    $link = $outline ? 'color:#0f172a' : 'color:#ffffff;border:1px solid #2f5bea';
@endphp
<tr><td class="btn"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" bgcolor="{{ $outline ? '#ffffff' : '#2f5bea' }}" style="{{ $cell }}"><a href="{{ $href }}" target="_blank" style="display:inline-block;padding:14px 28px;font-family:'DM Sans',Arial,Helvetica,sans-serif;font-size:15px;font-weight:600;line-height:20px;text-decoration:none;border-radius:999px;{{ $link }}">{{ $slot }}</a></td></tr></table></td></tr>
