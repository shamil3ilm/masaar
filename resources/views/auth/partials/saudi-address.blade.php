{{--
    A Saudi company's national address, as sign-up asks for it: the shapes
    ZATCA checks (BR-KSA-37, BR-KSA-66).
--}}
<fieldset class="space-y-4">
    <legend class="mb-2 text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('National address') }}</legend>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <x-ui.field :label="__('Street')">
            <x-ui.input type="text" name="street" maxlength="127" required value="{{ old('street') }}" />
        </x-ui.field>
        <x-ui.field :label="__('Building number')">
            <x-ui.input type="text" name="building_number" inputmode="numeric" maxlength="4" required value="{{ old('building_number') }}" />
        </x-ui.field>
        <x-ui.field :label="__('Additional street')">
            <x-ui.input type="text" name="additional_street" maxlength="127" value="{{ old('additional_street') }}" />
        </x-ui.field>
        <x-ui.field :label="__('District')">
            <x-ui.input type="text" name="district" maxlength="127" required value="{{ old('district') }}" />
        </x-ui.field>
        <x-ui.field :label="__('City')">
            <x-ui.input type="text" name="city" maxlength="127" required value="{{ old('city') }}" />
        </x-ui.field>
        <x-ui.field :label="__('Postal code')">
            <x-ui.input type="text" name="postal_code" inputmode="numeric" maxlength="5" required value="{{ old('postal_code') }}" />
        </x-ui.field>
    </div>
</fieldset>
