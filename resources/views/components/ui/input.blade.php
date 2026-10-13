@props(['type' => 'text'])

{{--
    A control someone types into.

    Forty-nine of these were written by hand in thirteen class strings, and
    the differences were all accidents rather than decisions: rounded against
    rounded-md, with and without shadow-sm, with and without the font-normal
    that stops a control inheriting its label's weight, and four different
    focus treatments including two with none at all.

    Every one of them was between thirty-six and thirty-eight pixels tall. The
    usual minimum for a touch target is forty-four, same as x-ui.button - and
    a date filter is harder to hit accurately than a button, not easier.

    The focus ring matches the button's, so focus looks like one thing moving
    through the page rather than a different idea per control.

    Attributes pass straight through, so type, name, value, required,
    maxlength, step, dir and autocomplete all work as they did. A caller that
    needs more - uppercase for a currency code, font-mono for a key - adds it
    with class, and it lands after the base.
--}}
<input type="{{ $type }}" {{ $attributes->merge([
    'class' => 'mt-1 block min-h-11 w-full rounded border-gray-300 text-sm font-normal text-gray-900 shadow-sm'
        .' focus:border-teal-600 focus:ring-1 focus:ring-teal-600',
]) }}>
