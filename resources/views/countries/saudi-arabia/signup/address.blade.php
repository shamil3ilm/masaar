{{--
    A Saudi company's national address, as sign-up asks for it: the shapes
    ZATCA checks (BR-KSA-37, BR-KSA-66).
--}}
<fieldset class="space-y-4">
    <legend class="text-base font-semibold text-fg">{{ __('National address') }}</legend>
    <p class="-mt-2 text-sm text-muted">{{ __('Your company\'s national address, as registered. It is printed on your tax invoices, and ZATCA checks its format.') }}</p>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <x-ui.field :label="__('Street')" error="street">
            <x-ui.input type="text" name="street" maxlength="127" required value="{{ old('street') }}" />
        </x-ui.field>
        <x-ui.field :label="__('Building number')" error="building_number" :hint="__('4 digits.')">
            <x-ui.input type="text" name="building_number" inputmode="numeric" maxlength="4" required dir="ltr" value="{{ old('building_number') }}" />
        </x-ui.field>
        <x-ui.field :label="__('Additional street')" error="additional_street" optional>
            <x-ui.input type="text" name="additional_street" maxlength="127" value="{{ old('additional_street') }}" />
        </x-ui.field>
        <x-ui.field :label="__('District')" error="district">
            <x-ui.input type="text" name="district" maxlength="127" required value="{{ old('district') }}" />
        </x-ui.field>
        <x-ui.field :label="__('City')" error="city">
            <x-ui.input type="text" name="city" maxlength="127" required value="{{ old('city') }}" />
        </x-ui.field>
        <x-ui.field :label="__('Postal code')" error="postal_code" :hint="__('5 digits.')">
            <x-ui.input type="text" name="postal_code" inputmode="numeric" maxlength="5" required dir="ltr" value="{{ old('postal_code') }}" />
        </x-ui.field>
    </div>
</fieldset>
