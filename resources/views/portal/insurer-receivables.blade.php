@extends('layouts.portal')

@section('title', __('Insurer receivables'))

{{--
    What insurers owe for their shares of split sales, by insurer
    (InsurerReceivables): the shares issued, the credit notes against them,
    and the claims still waiting for the insurer's decision.

    @param \Illuminate\Support\Collection $rows
--}}

@section('content')
<x-ui.page-header :title="__('Insurer receivables')"
                  :description="__('The insurers\' shares of your split sales that are issued, less the credit notes against them, by insurer.')">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Reports'), 'href' => route('portal.reports')], ['label' => __('Insurer receivables')]]" />
    </x-slot:breadcrumb>
</x-ui.page-header>

<x-ui.card class="overflow-hidden" flush>
        <x-ui.table :primary="[0, 6, 3]">
            <x-slot:head>
                <x-ui.th>{{ __('Insurer') }}</x-ui.th>
                <x-ui.th>{{ __('VAT number') }}</x-ui.th>
                <x-ui.th align="right">{{ __('Claims') }}</x-ui.th>
                <x-ui.th align="right">{{ __('Awaiting decision') }}</x-ui.th>
                <x-ui.th align="right">{{ __('Billed') }}</x-ui.th>
                <x-ui.th align="right">{{ __('Credited') }}</x-ui.th>
                <x-ui.th align="right">{{ __('Owed') }}</x-ui.th>
            </x-slot:head>
            @forelse ($rows as $row)
                <tr>
                    <td class="px-6 py-3 text-sm font-medium text-fg">{{ $row['insurer'] }}</td>
                    <td class="px-6 py-3 text-sm text-muted" dir="ltr">{{ $row['vat_number'] }}</td>
                    <td class="px-6 py-3 text-end text-sm tabular-nums">{{ $row['claims'] }}</td>
                    <td class="px-6 py-3 text-end text-sm tabular-nums">{{ $row['awaiting'] }}</td>
                    <td class="px-6 py-3 text-end text-sm tabular-nums">@include('portal.partials.money', ['amount' => $row['billed'], 'currency' => $row['currency']])</td>
                    <td class="px-6 py-3 text-end text-sm tabular-nums">@include('portal.partials.money', ['amount' => $row['credited'], 'currency' => $row['currency']])</td>
                    <td class="px-6 py-3 text-end text-sm font-semibold tabular-nums">@include('portal.partials.money', ['amount' => $row['owed'], 'currency' => $row['currency']])</td>
                </tr>
            @empty
                <x-ui.empty :columns="7">{{ __('No insurer has been billed yet. Split a sale with an insurer on the invoice form, and its share shows here once it is issued.') }}</x-ui.empty>
            @endforelse
        </x-ui.table>
</x-ui.card>
@endsection
