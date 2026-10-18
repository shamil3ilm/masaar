{{--
    A UAE organization's buyer details, which the UAE invoice form fills in:
    the emirate and the Peppol endpoint the buyer's service provider issued.
    The UAE module's InvoiceRules::buyerView(), checked by its buyerRules().

    @param \App\Domains\Invoice\Models\Customer $customer
--}}
            <x-ui.field :label="__('Emirate or region')">
                <x-ui.input type="text" name="subdivision" maxlength="50" list="emirate_codes" dir="ltr" value="{{ old('subdivision', $customer->subdivision) }}" />
                <span class="mt-1 block font-normal text-muted">{{ __('In the UAE, the emirate code: AUH, DXB, SHJ, AJM, UAQ, RAK or FUJ. Abroad, the buyer\'s region.') }}</span>
            </x-ui.field>
            <datalist id="emirate_codes">
                @include('portal.partials.emirate-options', ['selected' => null])
            </datalist>
            <x-ui.field :label="__('Peppol endpoint')">
                <x-ui.input type="text" name="peppol_endpoint" maxlength="15" dir="ltr" placeholder="0235:1234567890" value="{{ old('peppol_endpoint', $customer->peppol_endpoint) }}" />
                <span class="mt-1 block font-normal text-muted">{{ __('As the buyer\'s service provider issued it: 0235: followed by 10 digits. A buyer with none takes a reserved address: 0235:9900000098 (not yet on e-invoicing), 0235:9900000097 (deemed supply) or 0235:9900000099 (export).') }}</span>
            </x-ui.field>
