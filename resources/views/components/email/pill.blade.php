{{-- Status pill. tone: info (blue), warning (amber) or danger (red); colors from the email templates file. --}}
@props(['tone' => 'info', 'inline' => false])
@php
    $c = ['info' => ['#e8eefe', '#2349c9'], 'warning' => ['#fff7e6', '#b45309'], 'danger' => ['#fef2f2', '#c62828'], 'success' => ['#ecfdf3', '#15803d']][$tone];
    $pill = "<span style=\"display:inline-block;padding:4px 12px;border-radius:999px;background:{$c[0]};color:{$c[1]};font-family:'Inter',Arial,Helvetica,sans-serif;font-size:12px;font-weight:600;line-height:18px;white-space:nowrap\">".e($slot).'</span>';
@endphp
@if ($inline){!! $pill !!}@else<tr><td>{!! $pill !!}</td></tr>@endif
