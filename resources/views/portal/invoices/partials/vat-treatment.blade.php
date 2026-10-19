{{--
    A line's VAT treatment: its tax category, and when it is not standard-rated
    the reason, from the country's official code list (VatTreatments, the
    organization's module) with the authority's own texts.

    resources/js/invoice-vat.js keeps the two in step - a reason from another
    category is cleared, and the rate follows the category - and fills the
    reason in words from the official text. Without script the server does
    both, and refuses a reason from another category with the rule.

    Rendered inside a line row, so every field is nested in its label and
    carries no id (see line-row).

    @param int    $index       Position in the lines array.
    @param array  $row         Previously submitted values, or empty.
    @param \App\Domains\Compliance\Engine\Contracts\VatTreatments $treatments
    @param bool   $reasonText  Whether to ask for the reason in words.
    @param \App\Domains\Organization\DTOs\BusinessType|null $businessType
                                The organization's type: the reasons it
                                commonly needs are offered first.
--}}
@php
    $categories = $treatments->categories();
    $chosen = (string) (($row['tax_category'] ?? '') ?: $categories[0]->code);
    $exemption = (string) ($row['exempt_code'] ?? '');
    $hints = $businessType?->vatHints ?? [];
    $suggested = collect($treatments->exemptions())->filter(fn ($reason) => in_array($reason->code, $hints, true));
    $byCategory = collect($treatments->exemptions())->reject(fn ($reason) => in_array($reason->code, $hints, true))->groupBy('category');
@endphp
<x-ui.field :label="__('VAT treatment')" class="lg:col-span-2">
    <x-ui.select name="lines[{{ $index }}][tax_category]" data-tax-category>
        @foreach ($categories as $category)
            <option value="{{ $category->code }}" @selected($chosen === $category->code)>{{ $category->label }}</option>
        @endforeach
    </x-ui.select>
</x-ui.field>

<x-ui.field :label="__('Reason for zero rating or exemption')" class="sm:col-span-2 lg:col-span-4">
    <x-ui.select name="lines[{{ $index }}][exempt_code]" data-exempt-code>
        <option value="">{{ __('None') }}</option>
        @if ($suggested->isNotEmpty())
            <optgroup label="{{ __('Common for your business') }}">
                @foreach ($suggested as $reason)
                    <option value="{{ $reason->code }}" data-category="{{ $reason->category }}" data-text="{{ $reason->english }}"
                            @selected($exemption === $reason->code)>{{ $reason->label() }}</option>
                @endforeach
            </optgroup>
        @endif
        @foreach ($categories as $category)
            @if ($byCategory->has($category->code))
                <optgroup label="{{ $category->label }}">
                    @foreach ($byCategory[$category->code] as $reason)
                        <option value="{{ $reason->code }}" data-category="{{ $reason->category }}" data-text="{{ $reason->english }}"
                                @selected($exemption === $reason->code)>{{ $reason->label() }}</option>
                    @endforeach
                </optgroup>
            @endif
        @endforeach
    </x-ui.select>
</x-ui.field>

@if ($reasonText ?? true)
    <x-ui.field :label="__('Reason in words')" :hint="__('Filled in from the official text. Write your own only where the code has none, as out of scope.')" class="sm:col-span-2 lg:col-span-6">
        <x-ui.input type="text" name="lines[{{ $index }}][exempt_reason]" maxlength="255" data-exempt-reason
                    value="{{ $row['exempt_reason'] ?? '' }}" />
    </x-ui.field>
@endif
