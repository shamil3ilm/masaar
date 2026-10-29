{{--
    The legal registration a UAE document carries (IBR-172-AE, IBR-173-AE).
    Validated by CompanyProfileRules for 'AE'.

    Shared by the company page and sign-up, so the two ask the same way.

    @param array<string, string|null> $values  the stored values, by field name
--}}
<x-ui.field :label="__('Legal registration type')">
    {{-- Not required in the markup: an empty required select reads as invalid
         before anything is chosen. CompanyProfileRules asks for it. --}}
    <x-ui.select name="legal_registration_type">
        <option value="">{{ __('Choose...') }}</option>
        @include('portal.partials.legal-registration-options', ['selected' => old('legal_registration_type', $values['legal_registration_type'] ?? null)])
    </x-ui.select>
</x-ui.field>

<x-ui.field :label="__('Legal registration number')">
    <x-ui.input type="text" name="legal_registration_id" maxlength="100" required dir="ltr"
                value="{{ old('legal_registration_id', $values['legal_registration_id'] ?? null) }}" />
</x-ui.field>

<x-ui.field :label="__('Issuing authority')">
    <x-ui.input type="text" name="legal_registration_authority" maxlength="255"
                value="{{ old('legal_registration_authority', $values['legal_registration_authority'] ?? null) }}" />
    <span class="mt-1 block text-xs font-normal text-muted">{{ __('Required for a trade licence. For a passport, the two-letter code of the issuing country.') }}</span>
</x-ui.field>
