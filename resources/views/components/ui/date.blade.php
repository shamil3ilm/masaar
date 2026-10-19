@props(['value' => null, 'time' => false, 'zone' => null, 'empty' => '-'])
{{--
    A date, or a date and time, written one way everywhere.

    The screens wrote dates five ways - 2026-10-14, Oct 14, 2026, 14 Oct
    13:05, 2026-10-14 13:05:22 and the translated month - so one list showed
    an invoice's date one way and its submission another. This is the
    invoice's own: year-month-day, the form printed on it and filed with the
    authority, which reads the same in Arabic and English and sorts as it
    reads. Left to right in either language, so an Arabic page does not turn
    it around.

    value  a date, a Carbon or a string; null shows the empty mark
    time   true adds hours and minutes
    zone   the time zone to show it in - the organization's, where the
           clock on its wall is what a reader compares it with
    empty  what a missing date shows
--}}
@php($moment = blank($value) ? null : \Illuminate\Support\Carbon::parse($value))
@php($moment = $moment && $zone ? $moment->copy()->timezone($zone) : $moment)
@if ($moment)<time datetime="{{ $moment->toIso8601String() }}" dir="ltr" {{ $attributes->merge(['class' => 'tabular-nums']) }}>{{ $moment->format($time ? 'Y-m-d H:i' : 'Y-m-d') }}</time>@else<span {{ $attributes }}>{{ $empty }}</span>@endif
