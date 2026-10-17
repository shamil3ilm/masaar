{{--
    A Saudi company's registration with ZATCA, as sign-up asks for it. The
    caller supplies the grid around the fields.
--}}
<x-ui.field :label="__('VAT number')" error="vat_number"
            :hint="__('15 digits, starting and ending with 3, as on your VAT registration certificate.')">
    <x-ui.input type="text" name="vat_number" inputmode="numeric" maxlength="15" required dir="ltr" value="{{ old('vat_number') }}" />
</x-ui.field>
<x-ui.field :label="__('Commercial registration (CR) number')" error="cr_number" optional
            :hint="__('10 digits, from your commercial registration.')">
    <x-ui.input type="text" name="cr_number" inputmode="numeric" maxlength="10" dir="ltr" value="{{ old('cr_number') }}" />
</x-ui.field>
