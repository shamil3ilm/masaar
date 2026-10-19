{{--
    A UAE seller's tax identity: a TRN for a VAT-registered seller
    (IBR-132-AE), otherwise its TIN (IBR-148-AE). Validated by
    CompanyProfileRules for 'AE'.

    Shared by the company page and sign-up, so the two ask the same way.

    @param array<string, string|null> $values  the stored values, by field name
--}}
<x-ui.field :label="__('TRN')">
    <x-ui.input type="text" name="trn" inputmode="numeric" maxlength="15" dir="ltr"
                value="{{ old('trn', $values['trn'] ?? null) }}" />
    <span class="mt-1 block text-xs font-normal text-muted">{{ __('15 digits, starting with 1 and ending with 03. Leave empty if you are not registered for VAT.') }}</span>
</x-ui.field>

<x-ui.field :label="__('TIN')">
    <x-ui.input type="text" name="tin" inputmode="numeric" maxlength="10" dir="ltr"
                value="{{ old('tin', $values['tin'] ?? null) }}" />
    <span class="mt-1 block text-xs font-normal text-muted">{{ __('10 digits, starting with 1. With a TRN it is the TRN\'s first 10 digits and is filled in for you.') }}</span>
</x-ui.field>
