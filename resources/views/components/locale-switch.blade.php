{{--
    English or Arabic. A form rather than a link: it changes what is stored.
    Each option is written in its own language, so it can be found by someone
    who cannot read the current one.

    onDark: for a navy surface, where the accent teal is too dark to read.
--}}
@props(['onDark' => false])

<form method="POST" action="{{ route('locale') }}" {{ $attributes->class(['inline' => ! $attributes->has('class')]) }}>
    @csrf
    @if (app()->getLocale() === 'ar')
        <input type="hidden" name="locale" value="en">
        <x-ui.link-button :tone="$onDark ? 'inverse' : 'accent'" lang="en" dir="ltr">
            <svg class="size-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c2.5-2.4 3.75-5.4 3.75-9S14.5 5.4 12 3m0 18c-2.5-2.4-3.75-5.4-3.75-9S9.5 5.4 12 3M3.5 9h17m-17 6h17"/></svg>
            English
        </x-ui.link-button>
    @else
        <input type="hidden" name="locale" value="ar">
        <x-ui.link-button :tone="$onDark ? 'inverse' : 'accent'" lang="ar" dir="rtl">
            <svg class="size-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c2.5-2.4 3.75-5.4 3.75-9S14.5 5.4 12 3m0 18c-2.5-2.4-3.75-5.4-3.75-9S9.5 5.4 12 3M3.5 9h17m-17 6h17"/></svg>
            العربية
        </x-ui.link-button>
    @endif
</form>
