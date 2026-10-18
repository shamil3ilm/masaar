@props(['primary' => [0, 1], 'collapsible' => 'small'])

{{--
    A data table that can be read on a phone.

    Twelve screens wrote this shell by hand and every one of them put a
    min-w-full table inside a card with overflow-hidden, so the right-hand
    columns - often Actions - were unreachable on a phone. The scroll
    container is here because it is the part everybody forgot. tabindex makes
    it reachable by keyboard: a region that scrolls but cannot be focused can
    only be scrolled by a pointer.

    On a small screen a row does not scroll at all: it collapses to its key
    fields, and a button on the row (and a tap anywhere on it) opens it in
    place to show the rest and the row's actions. resources/js/ui.js does that
    from what is declared here - every list gets it by naming its key columns,
    not by writing markup of its own. Without script the scroll box remains.

    primary      the zero-based columns that stay visible on a compact row,
                 in order of importance; the first is the row's title.
    collapsible  'small' (below 640px, the default), 'always' (the style
                 guide's specimen), or 'never' (a table with nothing to hide).

    @slot head  the <x-ui.th> cells. Omit it for a table with no header.
--}}
<div class="overflow-x-auto" tabindex="0"
     data-collapsible="{{ $collapsible }}" data-primary="{{ implode(',', array_map('intval', (array) $primary)) }}"
     data-expand-label="{{ __('Show details') }}" data-collapse-label="{{ __('Hide details') }}">
    <table {{ $attributes->merge(['class' => 'min-w-full divide-y divide-line text-sm']) }}>
        @isset($head)
            <thead class="bg-sunken">
                <tr>{{ $head }}</tr>
            </thead>
        @endisset

        <tbody class="divide-y divide-line bg-surface">
            {{ $slot }}
        </tbody>
    </table>
</div>
