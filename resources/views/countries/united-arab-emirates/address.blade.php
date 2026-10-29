{{--
    A UAE seller's address. It names its emirate (IBR-128-AE) and has no
    fixed building number or postal code; most have no postal code at all.
    Validated by CompanyProfileRules for 'AE'.

    Shared by the company page and sign-up, so the two ask the same way. The
    caller supplies the grid around the fields.

    @param array<string, string|null> $values  the stored values, by field name
--}}
<x-ui.field :label="__('Street')">
    <x-ui.input type="text" name="street" maxlength="127" required
                value="{{ old('street', $values['street'] ?? null) }}" />
</x-ui.field>

<x-ui.field :label="__('Building or unit')">
    <x-ui.input type="text" name="building_number" maxlength="127"
                value="{{ old('building_number', $values['building_number'] ?? null) }}" />
</x-ui.field>

<x-ui.field :label="__('Additional street')">
    <x-ui.input type="text" name="additional_street" maxlength="127"
                value="{{ old('additional_street', $values['additional_street'] ?? null) }}" />
</x-ui.field>

<x-ui.field :label="__('Area')">
    <x-ui.input type="text" name="district" maxlength="127"
                value="{{ old('district', $values['district'] ?? null) }}" />
</x-ui.field>

<x-ui.field :label="__('City')">
    <x-ui.input type="text" name="city" maxlength="127" required
                value="{{ old('city', $values['city'] ?? null) }}" />
</x-ui.field>

<x-ui.field :label="__('Emirate')">
    {{-- Not required in the markup: an empty required select reads as invalid
         before anything is chosen. CompanyProfileRules asks for it. --}}
    <x-ui.select name="emirate">
        <option value="">{{ __('Choose...') }}</option>
        @include('portal.partials.emirate-options', ['selected' => old('emirate', $values['emirate'] ?? null)])
    </x-ui.select>
</x-ui.field>

<x-ui.field :label="__('P.O. box or postal code')">
    <x-ui.input type="text" name="postal_code" maxlength="20"
                value="{{ old('postal_code', $values['postal_code'] ?? null) }}" />
    <span class="mt-1 block text-xs font-normal text-muted">{{ __('Optional.') }}</span>
</x-ui.field>
