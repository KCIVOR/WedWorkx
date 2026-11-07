@props(['muted' => false])
<tr><td style="font-family:'Inter',Arial,Helvetica,sans-serif;font-size:{{ $muted ? 14 : 15 }}px;line-height:1.6;color:{{ $muted ? '#64748b' : '#334155' }}">{{ $slot }}</td></tr>
