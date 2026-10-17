@props(['type' => 'submit', 'tone' => 'accent'])

{{--
    A button that reads as a link: Sign out, a language switch.

    A form that changes something has to be submitted by a button, but in a
    footer or beside an email address a filled button is far louder than the
    action. This is that button, drawn as the text around it - and still 44px
    tall to the touch, because it is still something a thumb has to hit.

    tone: accent (the link colour), muted (secondary text), danger.
--}}
@php
    $colour = match ($tone) {
        'muted' => 'text-muted hover:text-fg',
        'danger' => 'text-danger',
        default => 'text-accent hover:text-accent-strong',
    };
@endphp

<button type="{{ $type }}" {{ $attributes->merge(['class' => 'inline-flex min-h-11 items-center gap-1.5 rounded text-sm font-medium underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus '.$colour]) }}>{{ $slot }}</button>
