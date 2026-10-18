{{--
    One line of a UAE invoice.

    The same shell as line-row, and for the same reasons: rendered for the
    rows a form starts or comes back with, cloned from a <template> by
    resources/js/invoice-lines.js, each input nested in its own label so a
    cloned row duplicates no id. What differs is PINT AE's: an item name
    beside the description, and the line's tax category, whose rate
    resources/js/uae-invoice.js fills in when the category changes.

    @param int   $index  Position in the lines array.
    @param array $row    Previously submitted values, or empty.
--}}
@php($category = (string) ($row['tax_category'] ?? 'S'))
<div data-line-row class="rounded border border-line p-4">
    <div class="mb-3 flex items-center justify-between">
        <span class="text-xs font-semibold uppercase tracking-wider text-subtle">
            {{ __('Line') }} <span data-line-number>{{ $index + 1 }}</span>
        </span>
        <x-ui.button type="button" size="sm" variant="ghost" data-remove-line class="-me-2 text-danger">{{ __('Remove') }}</x-ui.button>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
        @if (($products ?? collect())->isNotEmpty())
            {{-- Fills this row's fields and submits nothing itself. --}}
            <label class="block text-xs font-medium text-muted sm:col-span-2 lg:col-span-6">
                {{ __('Product') }}
                <x-ui.select data-product-picker>
                    <option value="">{{ __('Choose to fill in this line...') }}</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}" data-fields="{{ json_encode($product->uaeLineFields()) }}">
                            {{ $product->name }}{{ $product->sku ? ' ('.$product->sku.')' : '' }}
                        </option>
                    @endforeach
                </x-ui.select>
            </label>
        @endif
        {{-- The saved product the picker filled it from, if any: so that product is known to be used. --}}
        <input type="hidden" name="lines[{{ $index }}][product_id]" value="{{ $row['product_id'] ?? '' }}">

        <label class="block text-xs font-medium text-muted lg:col-span-2">
            {{ __('Item name') }}
            <x-ui.input type="text" name="lines[{{ $index }}][item_name]" maxlength="255"
                   value="{{ $row['item_name'] ?? $row['description'] ?? '' }}" />
        </label>

        <label class="block text-xs font-medium text-muted lg:col-span-4">
            {{ __('Description') }}
            <x-ui.input type="text" name="lines[{{ $index }}][description]" maxlength="255"
                   value="{{ $row['description'] ?? '' }}" />
        </label>

        <label class="block text-xs font-medium text-muted">
            {{ __('Quantity') }}
            <x-ui.input type="number" step="0.001" min="0" name="lines[{{ $index }}][quantity]"
                   value="{{ $row['quantity'] ?? '' }}" />
        </label>

        <label class="block text-xs font-medium text-muted">
            {{ __('Unit') }}
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

        <label class="block text-xs font-medium text-muted">
            {{ __('Unit price (excl. VAT)') }}
            <x-ui.input type="number" step="0.01" min="0" name="lines[{{ $index }}][unit_price]"
                   value="{{ $row['unit_price'] ?? '' }}" />
        </label>

        <label class="block text-xs font-medium text-muted">
            {{ __('Line discount') }}
            <x-ui.input type="number" step="0.01" min="0" name="lines[{{ $index }}][discount_amount]"
                   value="{{ $row['discount_amount'] ?? '' }}" />
        </label>

        <label class="block text-xs font-medium text-muted lg:col-span-2">
            {{ __('Tax category') }}
            <x-ui.select name="lines[{{ $index }}][tax_category]" data-tax-category>
                <option value="S" @selected($category === 'S')>{{ __('Standard rate') }}</option>
                <option value="Z" @selected($category === 'Z')>{{ __('Zero-rated') }}</option>
                <option value="E" @selected($category === 'E')>{{ __('Exempt') }}</option>
                <option value="O" @selected($category === 'O')>{{ __('Out of scope') }}</option>
                <option value="AE" @selected($category === 'AE')>{{ __('Reverse charge') }}</option>
                <option value="N" @selected($category === 'N')>{{ __('Margin scheme') }}</option>
            </x-ui.select>
        </label>

        <label class="block text-xs font-medium text-muted">
            {{ __('VAT %') }}
            <x-ui.input type="number" step="0.01" min="0" max="100" name="lines[{{ $index }}][tax_rate]"
                   value="{{ $row['tax_rate'] ?? \App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe::STANDARD_RATE }}" />
        </label>
    </div>

    {{-- Asked only of an exempt or a reverse-charge line; blank otherwise. --}}
    <div class="mt-3 grid gap-3 sm:grid-cols-3">
        <label class="block text-xs font-medium text-muted">
            {{ __('Exemption reason (exempt lines)') }}
            <x-ui.select name="lines[{{ $index }}][exempt_code]">
                <option value="">{{ __('None') }}</option>
                <option value="DL8.46.1" @selected(($row['exempt_code'] ?? '') === 'DL8.46.1')>DL8.46.1 &middot; {{ __('Financial services') }}</option>
                <option value="DL8.46.2" @selected(($row['exempt_code'] ?? '') === 'DL8.46.2')>DL8.46.2 &middot; {{ __('Residential buildings') }}</option>
                <option value="DL8.46.3" @selected(($row['exempt_code'] ?? '') === 'DL8.46.3')>DL8.46.3 &middot; {{ __('Bare land') }}</option>
                <option value="DL8.46.4" @selected(($row['exempt_code'] ?? '') === 'DL8.46.4')>DL8.46.4 &middot; {{ __('Local passenger transport') }}</option>
            </x-ui.select>
        </label>

        <label class="block text-xs font-medium text-muted">
            {{ __('Goods type (reverse charge)') }}
            <x-ui.select name="lines[{{ $index }}][goods_type]">
                <option value="">{{ __('None') }}</option>
                @foreach (\App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe::REVERSE_CHARGE_GOODS as $goods)
                    <option value="{{ $goods }}" @selected(($row['goods_type'] ?? '') === $goods)>{{ $goods }}</option>
                @endforeach
            </x-ui.select>
        </label>

        <label class="block text-xs font-medium text-muted">
            {{ __('Item GTIN (reverse charge)') }}
            <x-ui.input type="text" inputmode="numeric" maxlength="14" name="lines[{{ $index }}][item_id]" dir="ltr"
                   value="{{ $row['item_id'] ?? '' }}" />
        </label>
    </div>
</div>
