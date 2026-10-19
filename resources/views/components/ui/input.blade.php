@props(['type' => 'text'])

{{--
    A control someone types into.

    Forty-nine of these were written by hand in thirteen class strings, and
    the differences were all accidents rather than decisions. Every one was
    between thirty-six and thirty-eight pixels tall; this is forty-four, the
    same as x-ui.button, because a date filter is harder to hit accurately
    than a button, not easier.

    Drawn from the colour roles, so it sits on a card in light or dark without
    a dark: pair, and marked invalid by aria-invalid, so the red border and
    the announced error are one fact rather than two that can disagree.

    Attributes pass straight through, so type, name, value, required,
    maxlength, step, dir and autocomplete all work as they did. A caller that
    needs more - uppercase for a currency code, font-mono for a key - adds it
    with class, and it lands after the base.

    A number gets the phone keypad it needs without asking: decimal where
    its step has a fraction (an amount, a rate), numeric where it counts.
    It has no spinner (app.css): a price is typed, not clicked up a cent at
    a time, and the arrows sat on the wrong side in Arabic.
--}}
@php
    $keypad = $type === 'number' && ! $attributes->has('inputmode')
        ? (str_contains((string) $attributes->get('step', ''), '.') || $attributes->get('step') === 'any' ? 'decimal' : 'numeric')
        : null;
@endphp
<input type="{{ $type }}" @if ($keypad) inputmode="{{ $keypad }}" @endif {{ $attributes->merge([
    'class' => 'mt-1 block min-h-11 w-full rounded-control border border-line-strong bg-surface px-3 py-2 text-sm font-normal text-fg shadow-card'
        .' placeholder:text-subtle transition-colors'
        .' focus:border-focus focus:outline-none focus:ring-3 focus:ring-focus/25'
        .' disabled:cursor-not-allowed disabled:bg-sunken disabled:text-subtle aria-invalid:border-danger'
        .' file:me-3 file:rounded-control file:border-0 file:bg-sunken file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-fg',
]) }}>
