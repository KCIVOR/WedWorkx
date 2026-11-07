{{-- "What happens next" numbered list from the Payment receipt template. --}}
@props(['steps'])
<tr><td style="font-family:'Inter',Arial,Helvetica,sans-serif;font-size:11px;font-weight:600;letter-spacing:1.4px;text-transform:uppercase;color:#2f5bea">What happens next</td></tr>
<x-email.spacer />
<tr><td><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
@foreach ($steps as $i => $step)
<tr><td width="28" valign="top" style="padding:0 12px 14px 0"><div style="width:26px;height:26px;border-radius:50%;background:#e8eefe;color:#2f5bea;font-family:'Inter',Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;line-height:26px;text-align:center">{{ $i + 1 }}</div></td><td valign="top" style="padding:2px 0 14px;font-family:'Inter',Arial,Helvetica,sans-serif;font-size:14px;line-height:22px;color:#334155">{{ $step }}</td></tr>
@endforeach
</table></td></tr>
