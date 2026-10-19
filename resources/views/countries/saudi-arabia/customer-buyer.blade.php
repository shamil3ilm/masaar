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
            {{-- A citizen's private healthcare or education is zero-rated
                 (VATEX-SA-HEA, VATEX-SA-EDU), named by national ID
                 (BR-KSA-49): an invoice for this customer starts its lines
                 in it, unless a product says otherwise. --}}
            <x-ui.field :label="__('Citizen zero rating')" :hint="__('For a Saudi citizen receiving private healthcare or education. Needs the national ID above.')">
                <x-ui.select name="exempt_code">
                    <option value="">{{ __('None') }}</option>
                    @foreach (\App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraConfig::NAMED_BUYER_EXEMPTIONS as $code)
                        <option value="{{ $code }}" @selected((string) old('exempt_code', $customer->exempt_code) === $code)>{{ $code }} · {{ app()->getLocale() === 'ar' ? \App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraConfig::EXEMPTION_REASONS_AR[$code] : \App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraConfig::EXEMPTION_REASONS_EN[$code] }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
