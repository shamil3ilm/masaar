@extends('layouts.portal')

@section('title', __('Record a purchase'))

{{--
    A supplier's invoice or credit note, line by line with each line's VAT
    treatment, and the supplier's file.

    @param \Illuminate\Support\Collection $suppliers
    @param array<string, string> $treatments
    @param string $currency       the tax currency
    @param string $standardRate
    @param int $lines             how many line rows to offer
--}}

@section('content')
<x-ui.page-header :title="__('Record a purchase')"
                  :description="__('Amounts as the supplier\'s document states them. VAT left blank is worked out at the rate; a reverse charge line\'s VAT is assessed here.')">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Books'), 'href' => route('portal.books.index')], ['label' => __('Purchases'), 'href' => route('portal.books.purchases.index')], ['label' => __('Record a purchase')]]" />
    </x-slot:breadcrumb>
</x-ui.page-header>

@if ($suppliers->isEmpty())
    <x-ui.alert tone="warning" class="mb-6">{{ __('Add the supplier first, under Suppliers.') }}</x-ui.alert>
@endif

<form method="POST" action="{{ route('portal.books.purchases.store') }}" enctype="multipart/form-data" class="max-w-5xl space-y-6">
    @csrf
    <x-ui.card>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <x-ui.field :label="__('Supplier')" error="supplier_id">
                <x-ui.select name="supplier_id" required>
                    @foreach ($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected(old('supplier_id') === $supplier->id)>{{ $supplier->name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.field :label="__('Document')" error="document_type">
                <x-ui.select name="document_type">
                    <option value="invoice" @selected(old('document_type') !== 'credit_note')>{{ __('Invoice') }}</option>
                    <option value="credit_note" @selected(old('document_type') === 'credit_note')>{{ __('Credit note') }}</option>
                </x-ui.select>
            </x-ui.field>
            <x-ui.field :label="__('Number on the document')" error="supplier_invoice_number">
                <x-ui.input name="supplier_invoice_number" maxlength="100" required dir="ltr" value="{{ old('supplier_invoice_number') }}" />
            </x-ui.field>
            <x-ui.field :label="__('Document date')" error="invoice_date">
                <x-ui.date-input name="invoice_date" required value="{{ old('invoice_date', now()->toDateString()) }}" />
            </x-ui.field>
            <x-ui.field :label="__('Received on')" error="received_on" :hint="__('The day you received it, never before its date. Its input VAT goes in this day\'s VAT period.')" optional>
                <x-ui.date-input name="received_on" value="{{ old('received_on') }}" />
            </x-ui.field>
            <x-ui.field :label="__('Agreed payment date')" error="due_on" optional>
                <x-ui.date-input name="due_on" value="{{ old('due_on') }}" />
            </x-ui.field>
            @if ($onPayment)
                <div class="sm:col-span-2 lg:col-span-3">
                    <x-ui.checkbox name="paid_later" value="1" :checked="(bool) old('paid_later')"
                                   :description="__('Its input VAT is then recovered only as you pay it, in the period of each payment (Federal Decree-Law No. 8 of 2017 Art 55(1); Cabinet Decision No. 52 of 2017 Art 54).')">{{ __('Will not be paid within six months of the agreed payment date') }}</x-ui.checkbox>
                </div>
            @endif
            <x-ui.field :label="__('Currency')" error="currency">
                <x-ui.input name="currency" maxlength="3" required dir="ltr" value="{{ old('currency', $currency) }}" />
            </x-ui.field>
            <x-ui.field :label="__('Exchange rate to :currency', ['currency' => $currency])" error="exchange_rate" :hint="__('Only for another currency: the rate on the document\'s date.')" optional>
                <x-ui.input name="exchange_rate" inputmode="decimal" dir="ltr" value="{{ old('exchange_rate') }}" />
            </x-ui.field>
            <x-ui.field :label="__('Deductible share of the VAT (%)')" error="deductible_share" :hint="__('Less than 100 where the purchase is partly for exempt sales or private use.')">
                <x-ui.input name="deductible_share" inputmode="decimal" required dir="ltr" value="{{ old('deductible_share', '100') }}" />
            </x-ui.field>
            <x-ui.field :label="__('Invoice it credits')" error="original_number" :hint="__('For a credit note: the number of the supplier\'s invoice it corrects.')" optional>
                <x-ui.input name="original_number" maxlength="100" dir="ltr" value="{{ old('original_number') }}" />
            </x-ui.field>
            <x-ui.field :label="__('Note')" error="description" optional>
                <x-ui.input name="description" maxlength="500" value="{{ old('description') }}" />
            </x-ui.field>
        </div>
    </x-ui.card>

    <x-ui.card :title="__('Lines')" :description="__('Standard rate here is :rate%.', ['rate' => $standardRate])">
        <div class="space-y-4">
            @for ($i = 0; $i < $lines; $i++)
                <div class="grid grid-cols-1 gap-3 border-b border-line pb-4 sm:grid-cols-2 xl:grid-cols-6">
                    <x-ui.field :label="__('Description')" error="lines.{{ $i }}.description" class="sm:col-span-2 xl:col-span-2">
                        <x-ui.input name="lines[{{ $i }}][description]" maxlength="500" :required="$i === 0" value="{{ old('lines.'.$i.'.description') }}" />
                    </x-ui.field>
                    <x-ui.field :label="__('VAT treatment')" error="lines.{{ $i }}.treatment">
                        <x-ui.select name="lines[{{ $i }}][treatment]">
                            @foreach ($treatments as $value => $label)
                                <option value="{{ $value }}" @selected(old('lines.'.$i.'.treatment', 'standard') === $value)>{{ $label }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                    <x-ui.field :label="__('Amount before VAT')" error="lines.{{ $i }}.net">
                        <x-ui.input name="lines[{{ $i }}][net]" inputmode="decimal" dir="ltr" :required="$i === 0" value="{{ old('lines.'.$i.'.net') }}" />
                    </x-ui.field>
                    <x-ui.field :label="__('VAT')" error="lines.{{ $i }}.vat" optional>
                        <x-ui.input name="lines[{{ $i }}][vat]" inputmode="decimal" dir="ltr" value="{{ old('lines.'.$i.'.vat') }}" />
                    </x-ui.field>
                    <x-ui.field :label="__('VAT rate (%)')" error="lines.{{ $i }}.vat_rate" optional>
                        <x-ui.input name="lines[{{ $i }}][vat_rate]" inputmode="decimal" dir="ltr" placeholder="{{ $standardRate }}" value="{{ old('lines.'.$i.'.vat_rate') }}" />
                    </x-ui.field>
                    @if ($goods !== [])
                        <x-ui.field :label="__('Specified goods (domestic reverse charge)')" error="lines.{{ $i }}.goods_type" class="sm:col-span-2 xl:col-span-3" optional>
                            <x-ui.select name="lines[{{ $i }}][goods_type]">
                                <option value="">{{ __('None') }}</option>
                                @foreach ($goods as $code => $label)
                                    <option value="{{ $code }}" @selected(old('lines.'.$i.'.goods_type') === $code)>{{ __($label) }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                    @endif
                    <div class="sm:col-span-2 xl:col-span-6">
                        <x-ui.checkbox name="lines[{{ $i }}][capital_asset]" value="1" :checked="(bool) old('lines.'.$i.'.capital_asset')"
                                       :description="__('Kept for use over years, such as equipment or a vehicle.')">{{ __('Capital asset') }}</x-ui.checkbox>
                    </div>
                </div>
            @endfor
        </div>
    </x-ui.card>

    <x-ui.card>
        <x-ui.field :label="__('The supplier\'s document')" error="file" :hint="__('A PDF or a photo, kept with the purchase.')" optional>
            <x-ui.file name="file" :accept="$accept" :max-kb="$maxKb" />
        </x-ui.field>
    </x-ui.card>

    <x-ui.button>{{ __('Record purchase') }}</x-ui.button>
</form>
@endsection
