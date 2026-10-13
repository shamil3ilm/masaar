{{--
    A dropdown.

    Five different class strings for what is one control: rounded against
    rounded-md, with and without shadow-sm, and with and without the
    font-normal that stops it inheriting its label's weight when it is nested
    inside one.

    Same height and same focus ring as x-ui.input and x-ui.button, so a row of
    a filter bar lines up and focus looks like one thing moving through the
    page rather than three ideas.

    Options go in the slot, so an optgroup works as it would in plain markup -
    which the unit dropdown on an invoice line needs, forty codes being an
    unreadable flat list.
--}}
<select {{ $attributes->merge([
    'class' => 'mt-1 block min-h-11 w-full rounded border-gray-300 text-sm font-normal text-gray-900 shadow-sm'
        .' focus:border-teal-600 focus:ring-1 focus:ring-teal-600',
]) }}>
    {{ $slot }}
</select>
