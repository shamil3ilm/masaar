@props(['searchable' => null, 'source' => null])

{{--
    A dropdown.

    Same height, border and focus ring as x-ui.input and x-ui.button, so a row
    of a filter bar lines up and focus looks like one thing moving through the
    page rather than three ideas. The arrow is ours (ui-select in app.css),
    not the platform's, so it is the same chevron in every browser and on the
    left in Arabic.

    Options go in the slot, so an optgroup works as it would in plain markup -
    which the unit dropdown on an invoice line needs, forty codes being an
    unreadable flat list.

    searchable  a list you can type into (resources/js/combobox.js), over this
                same select, which still sends the value and works without
                script. Left unset, a list of more than ten options is
                searchable and a shorter one stays the browser's own.
    source      an address that searches for more options as you type, for a
                list too long to send whole (customers, products). It answers
                {data: [{value, label, fields?}]}.
--}}
@php
    $long = substr_count((string) $slot, '<option') > 10;
    $searchable = $searchable ?? ($long || $source !== null);
@endphp
@if ($searchable)<span data-combobox class="block">@endif
<select @if ($source) data-source="{{ $source }}" @endif {{ $attributes->merge([
    'class' => 'ui-select mt-1 block min-h-11 w-full appearance-none rounded-control border border-line-strong bg-surface py-2 ps-3 pe-10 text-sm font-normal text-fg shadow-card'
        .' transition-colors focus:border-focus focus:outline-none focus:ring-3 focus:ring-focus/25'
        .' disabled:cursor-not-allowed disabled:bg-sunken disabled:text-subtle aria-invalid:border-danger',
]) }}>
    {{ $slot }}
</select>
@if ($searchable)</span>@endif
