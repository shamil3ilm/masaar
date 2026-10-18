{{--
    A dropdown.

    Same height, border and focus ring as x-ui.input and x-ui.button, so a row
    of a filter bar lines up and focus looks like one thing moving through the
    page rather than three ideas.

    Options go in the slot, so an optgroup works as it would in plain markup -
    which the unit dropdown on an invoice line needs, forty codes being an
    unreadable flat list.
--}}
<select {{ $attributes->merge([
    'class' => 'mt-1 block min-h-11 w-full rounded-control border border-line-strong bg-surface px-3 py-2 text-sm font-normal text-fg shadow-card'
        .' transition-colors focus:border-focus focus:outline-none focus:ring-3 focus:ring-focus/25'
        .' disabled:cursor-not-allowed disabled:bg-sunken disabled:text-subtle aria-invalid:border-danger',
]) }}>
    {{ $slot }}
</select>
