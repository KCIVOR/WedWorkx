{{-- Label/value rows. Pass rows as [[label, value], ...]. Optional small-caps title row, as in the Payment receipt template. --}}
@props(['rows', 'title' => null])
<tr><td><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e2e8f0;border-radius:16px;border-collapse:separate;background:#ffffff">
@if ($title)
<tr><td colspan="2" style="padding:14px 18px 4px;font-family:'Inter',Arial,Helvetica,sans-serif;font-size:11px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:#64748b">{{ $title }}</td></tr>
@endif
@foreach ($rows as $i => [$label, $value])
@php $border = ($i || $title) ? 'border-top:1px solid #f1f5f9;' : ''; @endphp
<tr><td class="rk" style="{{ $border }}padding:12px 18px;font-family:'Inter',Arial,Helvetica,sans-serif;font-size:14px;line-height:20px;color:#64748b">{{ $label }}</td><td class="rv" align="right" style="{{ $border }}padding:12px 18px;font-family:'Inter',Arial,Helvetica,sans-serif;font-size:14px;line-height:20px;font-weight:600;color:#0f172a;text-align:right;word-break:break-word;overflow-wrap:anywhere">{{ $value }}</td></tr>
@endforeach
</table></td></tr>
