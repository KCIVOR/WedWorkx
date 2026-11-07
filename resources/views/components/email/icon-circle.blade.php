{{-- Round status mark at the top of the receipt and failed emails. tone: success (check) or danger (x). --}}
@props(['tone' => 'success'])
@php
    [$bg, $fg, $mark] = $tone === 'danger' ? ['#fef2f2', '#c62828', '&#10005;'] : ['#ecfdf3', '#15803d', '&#10003;'];
@endphp
<tr><td><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td width="48" height="48" align="center" valign="middle" style="width:48px;height:48px;border-radius:50%;background:{{ $bg }};color:{{ $fg }};font-family:Arial,Helvetica,sans-serif;font-size:22px;font-weight:700;line-height:48px">{!! $mark !!}</td></tr></table></td></tr>
