{{--
    English or Arabic. A form rather than a link: it changes what is stored.
    Each option is written in its own language, so it can be found by someone
    who cannot read the current one.
--}}
@props(['onDark' => false])

@php($tone = $onDark ? 'text-teal-300 hover:text-teal-200' : 'text-teal-700 dark:text-teal-300')
<form method="POST" action="{{ route('locale') }}" {{ $attributes->merge(['class' => 'inline']) }}>
    @csrf
    @if (app()->getLocale() === 'ar')
        <input type="hidden" name="locale" value="en">
        <button type="submit" lang="en" class="text-xs {{ $tone }} hover:underline">English</button>
    @else
        <input type="hidden" name="locale" value="ar">
        <button type="submit" lang="ar" class="text-xs {{ $tone }} hover:underline">العربية</button>
    @endif
</form>
