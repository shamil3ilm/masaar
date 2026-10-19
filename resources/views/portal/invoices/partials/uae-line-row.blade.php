{{--
    One line of a UAE invoice.

    The same shell as line-row, and for the same reasons: rendered for the
    rows a form starts or comes back with, cloned from a <template> by
    resources/js/invoice-lines.js, each input nested in its own label so a
    cloned row duplicates no id. What differs is PINT AE's: an item name
    beside the description, and the line's tax category, whose rate
    resources/js/invoice-vat.js fills in when the category changes.

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
            <x-ui.field :label="__('Product')" class="sm:col-span-2 lg:col-span-6">
                <x-ui.select data-product-picker>
                    <option value="">{{ __('Choose to fill in this line...') }}</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}" data-fields="{{ json_encode($product->uaeLineFields()) }}">
                            {{ $product->name }}{{ $product->sku ? ' ('.$product->sku.')' : '' }}
                        </option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
        @endif
        {{-- The saved product the picker filled it from, if any: so that product is known to be used. --}}
        <input type="hidden" name="lines[{{ $index }}][product_id]" value="{{ $row['product_id'] ?? '' }}">

        <x-ui.field :label="__('Item name')" class="lg:col-span-2">
            <x-ui.input type="text" name="lines[{{ $index }}][item_name]" maxlength="255"
                   value="{{ $row['item_name'] ?? $row['description'] ?? '' }}" />
        </x-ui.field>

        <x-ui.field :label="__('Description')" class="lg:col-span-4">
            <x-ui.input type="text" name="lines[{{ $index }}][description]" maxlength="255"
                   value="{{ $row['description'] ?? '' }}" />
        </x-ui.field>

        <x-ui.field :label="__('Quantity')">
            <x-ui.input type="number" step="0.001" min="0" name="lines[{{ $index }}][quantity]"
                   value="{{ $row['quantity'] ?? '' }}" />
        </x-ui.field>

        <x-ui.field :label="__('Unit')">
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
        </x-ui.field>

        <x-ui.field :label="__('Unit price (excl. VAT)')">
            <x-ui.input type="number" step="0.01" min="0" name="lines[{{ $index }}][unit_price]"
                   value="{{ $row['unit_price'] ?? '' }}" />
        </x-ui.field>

        <x-ui.field :label="__('Line discount')">
            <x-ui.input type="number" step="0.01" min="0" name="lines[{{ $index }}][discount_amount]"
                   value="{{ $row['discount_amount'] ?? '' }}" />
        </x-ui.field>

        <x-ui.field :label="__('Tax category')" class="lg:col-span-2">
            {{-- PINT AE's categories, from the module (VatTreatments). --}}
            <x-ui.select name="lines[{{ $index }}][tax_category]" data-tax-category>
                @foreach ($treatments->categories() as $option)
                    <option value="{{ $option->code }}" @selected($category === $option->code)>{{ $option->label }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>

        <x-ui.field :label="__('VAT %')">
            <x-ui.input type="number" step="0.01" min="0" max="100" name="lines[{{ $index }}][tax_rate]"
                   value="{{ $row['tax_rate'] ?? \App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe::STANDARD_RATE }}" />
        </x-ui.field>
    </div>

    {{-- Asked only of an exempt or a reverse-charge line; blank otherwise. --}}
    <div class="mt-3 grid gap-3 sm:grid-cols-3">
        <x-ui.field :label="__('Exemption reason (exempt lines)')">
            {{-- The exemption reasons of Aligned-TaxExemptionCodes (IBR-167-AE). --}}
            <x-ui.select name="lines[{{ $index }}][exempt_code]" data-exempt-code>
                <option value="">{{ __('None') }}</option>
                @foreach ($treatments->exemptions() as $reason)
                    <option value="{{ $reason->code }}" data-category="{{ $reason->category }}" @selected(($row['exempt_code'] ?? '') === $reason->code)>{{ $reason->label() }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>

        <x-ui.field :label="__('Goods type (reverse charge)')">
            <x-ui.select name="lines[{{ $index }}][goods_type]">
                <option value="">{{ __('None') }}</option>
                @foreach (\App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe::REVERSE_CHARGE_GOODS as $goods)
                    <option value="{{ $goods }}" @selected(($row['goods_type'] ?? '') === $goods)>{{ $goods }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>

        <x-ui.field :label="__('Item GTIN (reverse charge)')">
            <x-ui.input type="text" inputmode="numeric" maxlength="14" name="lines[{{ $index }}][item_id]" dir="ltr"
                   value="{{ $row['item_id'] ?? '' }}" />
        </x-ui.field>
    </div>

    {{-- The line's VAT and total as the draft will have them (resources/js/invoice-preview.js). --}}
    <p data-line-vat class="mt-3 text-sm text-muted" aria-live="polite"></p>
</div>
