{{--
    One charge on the whole invoice. Inputs are nested in their labels, as a
    line row's are, because a cloned row would duplicate any id.

    @param int   $index
    @param array $row  Previously submitted values, or empty.
--}}
<div data-charge-row class="rounded border border-line p-4">
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
        @if ($chargePresets !== [])
            {{-- Fills this row's fields and submits nothing itself. --}}
            <x-ui.field :label="__('Common charge')" class="xl:col-span-2">
                <x-ui.select data-charge-picker>
                    <option value="">{{ __('Choose to fill in this charge...') }}</option>
                    @foreach ($chargePresets as $preset)
                        <option value="{{ $preset['key'] }}" data-fields="{{ json_encode(array_filter([
                            'reason' => __($preset['reason']),
                            'reason_code' => $preset['reason_code'],
                            'amount' => $preset['amount'],
                            'percent' => $preset['percent'],
                            'tax_category' => $preset['tax_category'],
                            'tax_rate' => $preset['tax_rate'],
                        ], fn ($value): bool => $value !== null)) }}">{{ __($preset['reason']) }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
        @endif

        <x-ui.field :label="__('Reason')" class="xl:col-span-2">
            <x-ui.input type="text" maxlength="255" name="charges[{{ $index }}][reason]" value="{{ $row['reason'] ?? '' }}" />
        </x-ui.field>

        <x-ui.field :label="__('Amount')">
            <x-ui.input type="number" step="0.01" min="0" name="charges[{{ $index }}][amount]" value="{{ $row['amount'] ?? '' }}" />
        </x-ui.field>

        <x-ui.field :label="__('Or percent of the lines')">
            <x-ui.input type="number" step="0.01" min="0" max="100" name="charges[{{ $index }}][percent]" value="{{ $row['percent'] ?? '' }}" />
        </x-ui.field>

        <x-ui.field :label="__('Tax category')">
            <x-ui.select name="charges[{{ $index }}][tax_category]">
                @foreach ($chargeCategories as $code => $label)
                    <option value="{{ $code }}" @selected(($row['tax_category'] ?? array_key_first($chargeCategories)) === $code)>{{ $label }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>

        <x-ui.field :label="__('VAT %')">
            <x-ui.input type="number" step="0.01" min="0" max="100" name="charges[{{ $index }}][tax_rate]" value="{{ $row['tax_rate'] ?? $standardRate }}" />
        </x-ui.field>

        <x-ui.field :label="__('Exemption code')">
            <x-ui.input type="text" maxlength="50" name="charges[{{ $index }}][exempt_code]" value="{{ $row['exempt_code'] ?? '' }}" />
        </x-ui.field>

        <x-ui.field :label="__('Exemption reason')" class="xl:col-span-2">
            <x-ui.input type="text" maxlength="255" name="charges[{{ $index }}][exempt_reason]" value="{{ $row['exempt_reason'] ?? '' }}" />
        </x-ui.field>

        <div class="flex items-end">
            <x-ui.button type="button" size="sm" variant="ghost" data-remove-charge class="-ms-3 text-danger">{{ __('Remove') }}</x-ui.button>
        </div>
    </div>

    <input type="hidden" name="charges[{{ $index }}][reason_code]" value="{{ $row['reason_code'] ?? '' }}">
    <input type="hidden" name="charges[{{ $index }}][base_amount]" value="{{ $row['base_amount'] ?? '' }}">
</div>
