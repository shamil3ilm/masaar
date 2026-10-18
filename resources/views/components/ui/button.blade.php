@props(['variant' => 'primary', 'size' => 'md', 'href' => null, 'type' => 'submit'])

{{--
    An action, at a size a thumb can hit.

    Eighteen of these were written by hand in eleven different class strings,
    all saying nearly the same thing in a different order. Every one was about
    thirty-six pixels tall; the usual minimum for a touch target is forty-four,
    and min-h-11 is here so it cannot be left off - it was left off fourteen
    times out of eighteen.

    The variants are what the action does, not what colour it is:

      primary    the thing this screen is for - navy, teal after dark
      secondary  a supporting action, or an alternative to primary
      ghost      undo or dismiss - clear a filter, cancel
      danger     irreversible, or it stops something

    'quiet' and 'outline' are the names ghost and secondary had before the
    design system, kept so a call site written against them still draws the
    action it meant rather than falling through to primary.

    Sizes: sm for an action inside a table row, md everywhere else, lg for the
    one call to action on a public page. All three are at least 44px tall:
    sm is narrower and smaller-lettered, not shorter.

    href renders an anchor instead of a button. A link that looks like a
    button should still be a link, so it opens in a new tab and shows its
    target in the status bar.

    The focus ring is one treatment for every variant, so keyboard focus
    looks the same wherever it lands. focus-visible rather than focus: a
    pointer click should not leave a ring behind.
--}}
@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-button font-semibold'
        .' transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus'
        .' disabled:cursor-not-allowed disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50';

    $dimensions = match ($size) {
        'sm' => 'min-h-11 px-3 text-sm',
        'lg' => 'min-h-12 px-6 text-base',
        default => 'min-h-11 px-4 text-sm',
    };

    $look = match ($variant) {
        'secondary', 'outline' => 'border border-line-strong bg-surface text-fg hover:border-subtle hover:bg-sunken',
        'ghost', 'quiet' => 'text-fg hover:bg-sunken',
        'danger' => 'bg-danger text-on-danger hover:opacity-90',
        default => 'bg-action text-on-action hover:bg-action-hover',
    };
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $base.' '.$dimensions.' '.$look]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $base.' '.$dimensions.' '.$look]) }}>{{ $slot }}</button>
@endif
