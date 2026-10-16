{{--
    A Saudi company's registration with ZATCA, as sign-up asks for it. The
    caller supplies the grid around the fields.
--}}
<x-ui.field :label="__('VAT number')">
    <x-ui.input type="text" name="vat_number" inputmode="numeric" maxlength="15" required value="{{ old('vat_number') }}" />
</x-ui.field>
<x-ui.field :label="__('Commercial registration (CR) number')">
    <x-ui.input type="text" name="cr_number" inputmode="numeric" maxlength="10" value="{{ old('cr_number') }}" />
</x-ui.field>
