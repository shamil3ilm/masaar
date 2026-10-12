{{--
    One invoice line.

    Rendered server-side for the rows a form starts with or comes back with,
    and cloned from a <template> by resources/js/invoice-lines.js when a line
    is added. The same partial does both so the markup cannot drift between
    the row you are given and the row you add.

    The index in the name attributes is rewritten by that script after every
    add or remove: validation addresses a line by position, so a gap in the
    numbering puts the message on a field the form does not have.

    Each input is nested inside its own <label> rather than paired by id. A
    row is cloned, so any id in here would be duplicated the moment a second
    line is added - and a duplicated id associates the label with whichever
    field the browser finds first. Nesting associates them implicitly and has
    nothing to collide. Chrome reported ten unlabelled fields before this.

    @param int   $index  Position in the lines array.
    @param array $row    Previously submitted values, or empty.
--}}
<div data-line-row class="rounded border border-gray-200 p-4">
    <div class="mb-3 flex items-center justify-between">
        <span class="text-xs font-semibold uppercase tracking-wider text-gray-400">
            Line <span data-line-number>{{ $index + 1 }}</span>
        </span>
        <button type="button" data-remove-line
                class="text-xs text-gray-500 transition-colors hover:text-red-700 disabled:cursor-not-allowed disabled:text-gray-300 disabled:hover:text-gray-300">
            Remove
        </button>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
        <label class="block text-xs font-medium text-gray-600 lg:col-span-2">
            Description
            <input type="text" name="lines[{{ $index }}][description]"
                   value="{{ $row['description'] ?? '' }}"
                   class="mt-1 w-full rounded border-gray-300 text-sm font-normal text-gray-900 shadow-sm">
        </label>

        <label class="block text-xs font-medium text-gray-600 lg:col-span-2">
            Description in Arabic
            <input type="text" name="lines[{{ $index }}][description_ar]"
                   value="{{ $row['description_ar'] ?? '' }}"
                   dir="rtl" lang="ar"
                   class="mt-1 w-full rounded border-gray-300 text-sm font-normal text-gray-900 shadow-sm">
        </label>

        <label class="block text-xs font-medium text-gray-600">
            Quantity
            <input type="number" step="0.001" min="0" name="lines[{{ $index }}][quantity]"
                   value="{{ $row['quantity'] ?? '' }}"
                   class="mt-1 w-full rounded border-gray-300 text-sm font-normal text-gray-900 shadow-sm">
        </label>

        <label class="block text-xs font-medium text-gray-600">
            Unit price
            <input type="number" step="0.01" min="0" name="lines[{{ $index }}][unit_price]"
                   value="{{ $row['unit_price'] ?? '' }}"
                   class="mt-1 w-full rounded border-gray-300 text-sm font-normal text-gray-900 shadow-sm">
        </label>

        <label class="block text-xs font-medium text-gray-600">
            VAT %
            <input type="number" step="0.01" min="0" max="100" name="lines[{{ $index }}][tax_rate]"
                   value="{{ $row['tax_rate'] ?? '15' }}"
                   class="mt-1 w-full rounded border-gray-300 text-sm font-normal text-gray-900 shadow-sm">
        </label>

        <label class="block text-xs font-medium text-gray-600">
            Unit
            <select name="lines[{{ $index }}][unit_code]"
                    class="mt-1 w-full rounded border-gray-300 text-sm font-normal text-gray-900 shadow-sm">
                @foreach (['PCE' => 'Piece', 'KGM' => 'Kilogram', 'LTR' => 'Litre', 'MTR' => 'Metre', 'HUR' => 'Hour', 'DAY' => 'Day', 'MON' => 'Month', 'SET' => 'Set', 'BX' => 'Box', 'PK' => 'Pack', 'CT' => 'Carton'] as $code => $label)
                    <option value="{{ $code }}" @selected(($row['unit_code'] ?? 'PCE') === $code)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
    </div>
</div>
