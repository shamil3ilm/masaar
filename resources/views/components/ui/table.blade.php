{{--
    A data table that can be read on a phone.

    Twelve screens wrote this shell by hand and every one of them put a
    min-w-full table inside a card with overflow-hidden. min-w-full lets the
    table grow past its container, which eight columns at px-6 always do on a
    narrow viewport - and overflow-hidden then clips the overflow with nothing
    to scroll. So the right-hand columns were unreachable on a phone, and on
    several of these screens the right-hand column is Actions.

    The scroll container is here rather than in each view because it is the
    part everybody forgot. The card stays outside: a table is not always in
    one, and admin/organization-detail puts two in the same card.

    tabindex makes the scroll area reachable by keyboard. A region that
    scrolls but cannot be focused can only be scrolled by a pointer.

    @slot head  the <x-ui.th> cells. Omit it for a table with no header.
--}}
<div class="overflow-x-auto" tabindex="0">
    <table {{ $attributes->merge(['class' => 'min-w-full divide-y divide-gray-200 dark:divide-gray-700']) }}>
        @isset($head)
            <thead class="bg-gray-50 dark:bg-gray-950">
                <tr>{{ $head }}</tr>
            </thead>
        @endisset

        <tbody class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-900">
            {{ $slot }}
        </tbody>
    </table>
</div>
