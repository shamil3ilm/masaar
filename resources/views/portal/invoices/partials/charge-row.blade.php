{{--
    One charge on the whole invoice. Inputs are nested in their labels, as a
    line row's are, because a cloned row would duplicate any id.

    @param int   $index
    @param array $row  Previously submitted values, or empty.
--}}
<div data-charge-row class="rounded border border-gray-200 dark:border-gray-700 p-4">
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
        @if ($chargePresets !== [])
            {{-- Fills this row's fields and submits nothing itself. --}}
            <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 lg:col-span-2">
                {{ __('Common charge') }}
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
            </label>
        @endif

        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 lg:col-span-2">
            {{ __('Reason') }}
            <x-ui.input type="text" maxlength="255" name="charges[{{ $index }}][reason]" value="{{ $row['reason'] ?? '' }}" />
        </label>

        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">
            {{ __('Amount') }}
            <x-ui.input type="number" step="0.01" min="0" name="charges[{{ $index }}][amount]" value="{{ $row['amount'] ?? '' }}" />
        </label>

        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">
            {{ __('Or percent of the lines') }}
            <x-ui.input type="number" step="0.01" min="0" max="100" name="charges[{{ $index }}][percent]" value="{{ $row['percent'] ?? '' }}" />
        </label>

        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">
            {{ __('Tax category') }}
            <x-ui.select name="charges[{{ $index }}][tax_category]">
                @foreach ($chargeCategories as $code => $label)
                    <option value="{{ $code }}" @selected(($row['tax_category'] ?? array_key_first($chargeCategories)) === $code)>{{ $label }}</option>
                @endforeach
            </x-ui.select>
        </label>

        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">
            {{ __('VAT %') }}
            <x-ui.input type="number" step="0.01" min="0" max="100" name="charges[{{ $index }}][tax_rate]" value="{{ $row['tax_rate'] ?? $standardRate }}" />
        </label>

        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">
            {{ __('Exemption code') }}
            <x-ui.input type="text" maxlength="50" name="charges[{{ $index }}][exempt_code]" value="{{ $row['exempt_code'] ?? '' }}" />
        </label>

        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 lg:col-span-2">
            {{ __('Exemption reason') }}
            <x-ui.input type="text" maxlength="255" name="charges[{{ $index }}][exempt_reason]" value="{{ $row['exempt_reason'] ?? '' }}" />
        </label>

        <div class="flex items-end">
            <button type="button" data-remove-charge class="text-xs text-gray-500 dark:text-gray-400 transition-colors hover:text-red-700 dark:hover:text-red-300">
                {{ __('Remove') }}
            </button>
        </div>
    </div>

    <input type="hidden" name="charges[{{ $index }}][reason_code]" value="{{ $row['reason_code'] ?? '' }}">
    <input type="hidden" name="charges[{{ $index }}][base_amount]" value="{{ $row['base_amount'] ?? '' }}">
</div>
