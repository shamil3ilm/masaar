{{--
    A Saudi organization's buyer details beyond the VAT number: the other
    identifier a buyer without one is invoiced by (BT-46), and the register
    it is from (BT-46-1). The Saudi module's InvoiceRules::buyerView(),
    checked by its buyerRules().

    @param \App\Domains\Invoice\Models\Customer $customer
--}}
            <x-ui.field :label="__('Other identifier (if no VAT number)')">
                <x-ui.select name="buyer_id_scheme">
                    <option value="">{{ __('None') }}</option>
                    @foreach (\App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraConfig::BUYER_ID_SCHEME_LABELS as $scheme => [$english, $arabicLabel])
                        <option value="{{ $scheme }}" @selected((string) old('buyer_id_scheme', $customer->buyer_id_scheme) === (string) $scheme)>{{ app()->getLocale() === 'ar' ? $arabicLabel : $english }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.field :label="__('Identifier number')">
                <x-ui.input type="text" name="buyer_id" maxlength="50" dir="ltr" value="{{ old('buyer_id', $customer->buyer_id) }}" />
            </x-ui.field>
