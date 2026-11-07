{{-- Ten-segment meter ("slot meter" in the design system). --}}
@props(['taken', 'total' => 10])
<tr><td><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
@for ($i = 1; $i <= $total; $i++)
<td width="{{ intdiv(100, $total) }}%" style="padding:0 2px"><div style="height:6px;line-height:6px;font-size:0;border-radius:999px;background:{{ $i <= $taken ? '#2f5bea' : '#e2e8f0' }}">&nbsp;</div></td>
@endfor
</tr></table><div style="padding-top:8px;font-family:'Inter',Arial,Helvetica,sans-serif;font-size:12px;color:#64748b">{{ $taken }} of {{ $total }} slots taken</div></td></tr>
