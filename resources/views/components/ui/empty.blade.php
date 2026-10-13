@props(['columns'])

{{--
    The row a table shows when it has none.

    Ten views wrote this by hand in four paddings - py-4, py-8, py-10 and
    py-12 - so the same situation looked like a different kind of situation
    depending on which screen you were on.

    columns has to be told, because Blade cannot count the headings above it.
    TableMarkupTest asserts it matches: a colspan short of the column count
    leaves the message boxed into the left of the table rather than centred
    under it, which is the mistake that happens when a column is added later.
--}}
<tr>
    <td colspan="{{ $columns }}" class="px-6 py-10 text-center text-gray-500">
        {{ $slot }}
    </td>
</tr>
