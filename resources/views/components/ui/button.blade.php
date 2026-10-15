@props(['variant' => 'primary', 'href' => null, 'type' => 'submit'])

{{--
    An action, at a size a thumb can hit.

    Eighteen of these were written by hand in eleven different class strings,
    all saying nearly the same thing in a different order: rounded against
    rounded-md, font-medium against font-semibold against neither, text-sm
    before the colour or after it. Four of them had no hover state.

    Every one of them was px-4 py-2 text-sm, which is about thirty-six pixels
    tall. The usual minimum for a touch target is forty-four, and these are
    the Filter, Clear, Retry and Issue controls on screens a taxpayer uses on
    a phone. min-h-11 is that forty-four pixels, and it is here so it cannot
    be left off - it was left off fourteen times out of eighteen.

    The variants are what the action does, not what colour it is:

      primary    the thing this screen is for
      secondary  a supporting action - filter, refresh
      quiet      undo or dismiss - clear a filter, cancel
      outline    an alternative to primary, equal weight, lower emphasis
      danger     irreversible, or it stops something

    href renders an anchor instead of a button. A link that looks like a
    button should still be a link, so it opens in a new tab and shows its
    target in the status bar.

    The focus ring is one treatment for every variant, so keyboard focus
    looks the same wherever it lands. focus-visible rather than focus: a
    pointer click should not leave a ring behind.
--}}
@php
    $base = 'inline-flex min-h-11 items-center justify-center gap-2 rounded px-4 py-2 text-sm font-semibold'
        .' transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink-900'
        .' disabled:cursor-not-allowed disabled:opacity-50';

    $look = match ($variant) {
        'secondary' => 'bg-gray-600 text-white hover:bg-gray-700',
        'quiet' => 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-300 dark:hover:bg-gray-600',
        'outline' => 'border border-teal-700 text-teal-700 dark:text-teal-300 hover:bg-teal-50 dark:hover:bg-teal-950/40',
        'danger' => 'bg-red-600 text-white hover:bg-red-700',
        default => 'bg-teal-600 text-white hover:bg-teal-700',
    };
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $base.' '.$look]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $base.' '.$look]) }}>{{ $slot }}</button>
@endif
