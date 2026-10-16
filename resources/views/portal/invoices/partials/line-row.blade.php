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
<div data-line-row class="rounded border border-gray-200 dark:border-gray-700 p-4">
    <div class="mb-3 flex items-center justify-between">
        <span class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
            {{ __('Line') }} <span data-line-number>{{ $index + 1 }}</span>
        </span>
        <button type="button" data-remove-line
                class="text-xs text-gray-500 dark:text-gray-400 transition-colors hover:text-red-700 dark:hover:text-red-300 disabled:cursor-not-allowed disabled:text-gray-300 dark:disabled:text-gray-600 disabled:hover:text-gray-300 dark:disabled:hover:text-gray-600">
            {{ __('Remove') }}
        </button>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
        @if (($products ?? collect())->isNotEmpty())
            {{-- Fills this row's fields and submits nothing itself. --}}
            <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 sm:col-span-2 lg:col-span-6">
                {{ __('Product') }}
                <x-ui.select data-product-picker>
                    <option value="">{{ __('Choose to fill in this line...') }}</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}" data-fields="{{ json_encode($product->lineFields()) }}">
                            {{ $product->name }}{{ $product->sku ? ' ('.$product->sku.')' : '' }}
                        </option>
                    @endforeach
                </x-ui.select>
            </label>
        @endif

        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 lg:col-span-2">
            {{ __('Description') }}
            <x-ui.input type="text" name="lines[{{ $index }}][description]"
                   value="{{ $row['description'] ?? '' }}" />
        </label>

        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 lg:col-span-2">
            {{ __('Description in Arabic') }}
            <x-ui.input type="text" name="lines[{{ $index }}][description_ar]"
                   value="{{ $row['description_ar'] ?? '' }}"
                   dir="rtl" lang="ar" />
        </label>

        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">
            {{ __('Quantity') }}
            <x-ui.input type="number" step="0.001" min="0" name="lines[{{ $index }}][quantity]"
                   value="{{ $row['quantity'] ?? '' }}" />
        </label>

        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">
            {{ __('Unit price') }}
            <x-ui.input type="number" step="0.01" min="0" name="lines[{{ $index }}][unit_price]"
                   value="{{ $row['unit_price'] ?? '' }}" />
        </label>

        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">
            {{ __('VAT %') }}
            <x-ui.input type="number" step="0.01" min="0" max="100" name="lines[{{ $index }}][tax_rate]"
                   value="{{ $row['tax_rate'] ?? '15' }}" />
        </label>

        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">
            {{ __('Unit') }}
            {{-- Grouped, and read from App\Domains\Invoice\Support\UnitCode.

                 This list used to be written out here: eleven codes against
                 the eighteen the API accepted, so a seller pricing by the
                 gram could file through the API and not from the portal. Four
                 of the eleven - PCE, BX, PK, CT - are in neither UN/ECE
                 Recommendation 20 nor Peppol's Recommendation 21 extension.

                 normalise() maps a stored legacy code onto its replacement,
                 so an older draft still opens with the right option on. --}}
            <x-ui.select name="lines[{{ $index }}][unit_code]">
                @php($selected = \App\Domains\Invoice\Support\UnitCode::normalise($row['unit_code'] ?? null))
                @foreach (\App\Domains\Invoice\Support\UnitCode::GROUPS as $group => $codes)
                    <optgroup label="{{ __($group) }}">
                        @foreach ($codes as $code => $label)
                            <option value="{{ $code }}" @selected($selected === $code)>{{ __($label) }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </x-ui.select>
        </label>
    </div>
</div>
