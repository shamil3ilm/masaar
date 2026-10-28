@extends('layouts.portal')

@section('title', __('Vouchers'))

{{--
    Vouchers sold, what is left on each, and the forms to sell one and to
    use one against an issued invoice (Vouchers). Selling a voucher is not a
    supply: no tax invoice, no VAT. The invoice it pays carries the VAT.

    @param \Illuminate\Support\Collection<int, array<string, mixed>> $vouchers
    @param array<string, string> $methods
--}}

@section('content')
<x-ui.page-header :title="__('Vouchers')"
                  :description="__('Selling a voucher is not a supply: no tax invoice is issued and no VAT is due. VAT is charged on the invoice the voucher later pays.')">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Books'), 'href' => route('portal.books.index')], ['label' => __('Vouchers')]]" />
    </x-slot:breadcrumb>
</x-ui.page-header>

<div class="mb-6 grid gap-6 lg:grid-cols-2">
    <x-ui.card :title="__('Sell a voucher')" :description="__('At its face value. Charge any fee on its own invoice.')">
        <form method="POST" action="{{ route('portal.books.vouchers.store') }}" class="space-y-4" data-voucher-sale>
            @csrf
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field :label="__('Face value')" error="face_value">
                    <x-ui.input type="number" step="0.01" min="0.01" name="face_value" value="{{ old('face_value') }}" required />
                </x-ui.field>
                <x-ui.field :label="__('Code')" :hint="__('Left empty, one is made for you.')" error="code" optional>
                    <x-ui.input name="code" value="{{ old('code') }}" maxlength="40" dir="ltr" />
                </x-ui.field>
                <x-ui.field :label="__('Sold on')" error="sold_on">
                    <x-ui.date-input name="sold_on" value="{{ old('sold_on', now()->toDateString()) }}" required />
                </x-ui.field>
                <x-ui.field :label="__('Expires on')" error="expires_on" optional>
                    <x-ui.date-input name="expires_on" value="{{ old('expires_on') }}" />
                </x-ui.field>
                <x-ui.field :label="__('Holder')" error="holder_name" optional>
                    <x-ui.input name="holder_name" value="{{ old('holder_name') }}" maxlength="255" />
                </x-ui.field>
                <x-ui.field :label="__('Paid by')" error="payment_method">
                    <x-ui.select name="payment_method">
                        @foreach ($methods as $value => $label)
                            <option value="{{ $value }}" @selected(old('payment_method', 'cash') === $value)>{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </div>
            <x-ui.button>{{ __('Record the sale') }}</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card :title="__('Use a voucher')" :description="__('As payment of an issued invoice, which bears the VAT.')">
        <form method="POST" action="{{ route('portal.books.vouchers.redeem') }}" class="space-y-4" data-voucher-redeem>
            @csrf
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field :label="__('Code')" error="code">
                    <x-ui.input name="code" value="{{ old('code') }}" maxlength="40" required dir="ltr" />
                </x-ui.field>
                <x-ui.field :label="__('Invoice number')" error="invoice_number">
                    <x-ui.input name="invoice_number" value="{{ old('invoice_number') }}" maxlength="255" required dir="ltr" />
                </x-ui.field>
                <x-ui.field :label="__('Amount')" :hint="__('Left empty, as much as the voucher and the invoice allow.')" error="amount" optional>
                    <x-ui.input type="number" step="0.01" min="0.01" name="amount" value="{{ old('amount') }}" />
                </x-ui.field>
                <x-ui.field :label="__('Used on')" error="redeemed_on">
                    <x-ui.date-input name="redeemed_on" value="{{ old('redeemed_on', now()->toDateString()) }}" required />
                </x-ui.field>
            </div>
            <x-ui.button>{{ __('Use the voucher') }}</x-ui.button>
        </form>
    </x-ui.card>
</div>

<x-ui.card class="overflow-hidden" flush>
    <x-ui.table :primary="[0, 3, 4]">
        <x-slot:head>
            <x-ui.th>{{ __('Code') }}</x-ui.th>
            <x-ui.th>{{ __('Sold on') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Face value') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Left') }}</x-ui.th>
            <x-ui.th>{{ __('Status') }}</x-ui.th>
            <x-ui.th>{{ __('Expires on') }}</x-ui.th>
        </x-slot:head>
        @forelse ($vouchers as $voucher)
            <tr class="hover:bg-sunken" data-voucher>
                <td class="px-6 py-3 text-sm text-fg" dir="ltr">{{ $voucher['code'] }}</td>
                <td class="px-6 py-3 text-sm"><x-ui.date :value="$voucher['sold_on']" /></td>
                <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$voucher['face_value']" :currency="$voucher['currency']" /></td>
                <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$voucher['balance']" :currency="$voucher['currency']" /></td>
                <td class="px-6 py-3 text-sm">
                    <x-ui.badge :tone="['active' => 'success', 'used' => 'neutral', 'expired' => 'warning'][$voucher['status']]"
                                :label="['active' => __('Active'), 'used' => __('Used'), 'expired' => __('Expired')][$voucher['status']]" />
                </td>
                <td class="px-6 py-3 text-sm">@if ($voucher['expires_on'])<x-ui.date :value="$voucher['expires_on']" />@endif</td>
            </tr>
        @empty
            <x-ui.empty :columns="6">{{ __('No vouchers sold yet.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</x-ui.card>
@endsection
