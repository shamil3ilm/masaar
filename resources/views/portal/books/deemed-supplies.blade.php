@extends('layouts.portal')

@section('title', __('Deemed supplies'))

{{--
    Deemed supplies recorded, with their outcome, and the form to record one
    (DeemedSupplies). A taxed one has a kept invoice stating its output tax,
    which is what the VAT return counts.

    @param \Illuminate\Support\Collection<int, \App\Domains\Books\Models\DeemedSupply> $records
    @param array<string, string> $kinds
    @param \App\Domains\Compliance\Engine\DTOs\DeemedSupplyRules $rules
--}}

@section('content')
<x-ui.page-header :title="__('Deemed supplies')"
                  :description="__('Goods or services taken for a non-business use, given away, or kept on deregistration, on which you deducted input tax.')">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Books'), 'href' => route('portal.books.index')], ['label' => __('Deemed supplies')]]" />
    </x-slot:breadcrumb>
</x-ui.page-header>

<x-ui.alert tone="info" class="mb-6">
    {{ __('Gifts and samples are not taxed while one recipient\'s total stays within :allowance in the period (:source). A taxed one makes an invoice you keep: no customer is invoiced, and its output tax goes in the return with your standard-rated sales.', ['allowance' => $rules->recipientAllowance, 'source' => $rules->source]) }}
</x-ui.alert>

<x-ui.card class="mb-6" :title="__('Record a deemed supply')">
    <form method="POST" action="{{ route('portal.books.deemed-supplies.store') }}" class="space-y-4" data-deemed-supply>
        @csrf
        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.field :label="__('Kind')" error="kind">
                <x-ui.select name="kind">
                    @foreach ($kinds as $value => $label)
                        <option value="{{ $value }}" @selected(old('kind') === $value)>{{ $label }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.field :label="__('Supplied on')" :hint="__('On deregistration, the day it took effect.')" error="supplied_on">
                <x-ui.date-input name="supplied_on" value="{{ old('supplied_on', now()->toDateString()) }}" required />
            </x-ui.field>
            <x-ui.field :label="__('Description')" error="description">
                <x-ui.input name="description" value="{{ old('description') }}" maxlength="150" required />
            </x-ui.field>
            <x-ui.field :label="__('Description in Arabic')" error="description_ar" optional>
                <x-ui.input name="description_ar" value="{{ old('description_ar') }}" maxlength="150" dir="rtl" />
            </x-ui.field>
            <x-ui.field :label="__('Recipient')" :hint="__('Who received a gift, a sample or goods given to an employee.')" error="recipient" optional>
                <x-ui.input name="recipient" value="{{ old('recipient') }}" maxlength="255" />
            </x-ui.field>
            <x-ui.field :label="__('Value before VAT')" :hint="__('What it cost you, or its market value where the cost is unknown or on deregistration.')" error="value">
                <x-ui.input type="number" step="0.01" min="0.01" name="value" value="{{ old('value') }}" required />
            </x-ui.field>
            <x-ui.field :label="__('Input tax deducted %')" :hint="__('The share of the input tax on it you deducted. None: it is not a deemed supply.')" error="input_tax_percent" optional>
                <x-ui.input type="number" step="0.01" min="0" max="100" name="input_tax_percent" value="{{ old('input_tax_percent', '100') }}" />
            </x-ui.field>
        </div>
        <x-ui.button>{{ __('Record') }}</x-ui.button>
    </form>
</x-ui.card>

<x-ui.card class="overflow-hidden" flush>
    <x-ui.table :primary="[1, 2, 5]">
        <x-slot:head>
            <x-ui.th>{{ __('Date') }}</x-ui.th>
            <x-ui.th>{{ __('Description') }}</x-ui.th>
            <x-ui.th>{{ __('Kind') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Value before VAT') }}</x-ui.th>
            <x-ui.th align="right">{{ __('VAT') }}</x-ui.th>
            <x-ui.th>{{ __('Outcome') }}</x-ui.th>
        </x-slot:head>
        @forelse ($records as $record)
            <tr class="hover:bg-sunken" data-deemed-row>
                <td class="px-6 py-3 text-sm"><x-ui.date :value="$record->supplied_on" /></td>
                <td class="px-6 py-3 text-sm text-fg">
                    {{ $record->description }}@if ($record->recipient) <span class="text-muted">- {{ $record->recipient }}</span>@endif
                    <span class="block text-xs text-muted">{{ $record->reason }}</span>
                </td>
                <td class="px-6 py-3 text-sm">{{ $record->kind->label() }}</td>
                <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$record->value" /></td>
                <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$record->tax_amount" /></td>
                <td class="px-6 py-3 text-sm">
                    @if ($record->invoice_id)
                        <a href="{{ route('portal.invoices.show', $record->invoice_id) }}" class="text-accent hover:underline">{{ __('Taxed: its invoice') }}</a>
                    @else
                        <x-ui.badge :tone="$record->outcome === 'relieved' ? 'success' : 'neutral'" :label="$record->outcome === 'relieved' ? __('Relieved') : ($record->outcome === 'taxed' ? __('Taxed') : __('Not a deemed supply'))" />
                    @endif
                </td>
            </tr>
        @empty
            <x-ui.empty :columns="6">{{ __('No deemed supplies recorded.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</x-ui.card>
@endsection
