@extends('layouts.admin')

@section('title', __('Reconciliation report'))

@section('content')
<x-ui.page-header :title="__('Reconciliation report')" :description="__('Every statement line imported, by currency: where the money in stands, how it was matched, and what was taken as fees, left short or kept over.')">
    <x-slot:actions>
        <x-ui.button variant="secondary" :href="route('admin.finance.reconciliation.lines.export')">{{ __('Download lines as CSV') }}</x-ui.button>
    </x-slot:actions>
</x-ui.page-header>

@include('admin.finance.reconciliation.nav', ['current' => 'reports'])

@forelse ($summary as $currency => $row)
    <x-ui.card class="mb-6" :title="__('In :currency', ['currency' => $currency])" flush>
        <x-ui.table :primary="[0, 2]">
            <x-slot:head>
                <x-ui.th>{{ __('Lines') }}</x-ui.th>
                <x-ui.th align="right">{{ __('Count') }}</x-ui.th>
                <x-ui.th align="right">{{ __('Amount') }}</x-ui.th>
            </x-slot:head>
            @foreach (['in' => __('Money in'), 'matched' => __('Matched'), 'suggested' => __('Suggested'), 'unmatched' => __('Unmatched'), 'ignored' => __('Set aside'), 'out' => __('Money out')] as $key => $label)
                <tr class="hover:bg-sunken">
                    <td class="px-6 py-3 text-sm text-fg">{{ $label }}</td>
                    <td class="px-6 py-3 text-right text-sm tabular-nums text-fg">{{ $row[$key.'_count'] }}</td>
                    <td class="px-6 py-3 text-right text-sm text-fg"><x-ui.amount :value="$row[$key.'_amount']" :currency="$currency" /></td>
                </tr>
            @endforeach
        </x-ui.table>
        <div class="grid grid-cols-2 gap-4 border-t border-line p-5 sm:grid-cols-3 lg:grid-cols-6">
            <x-ui.stat :label="__('Confirmed automatically')" :value="(string) $row['auto']" />
            <x-ui.stat :label="__('Confirmed by an admin')" :value="(string) $row['by_admin']" />
            <x-ui.stat :label="__('Undone')" :value="(string) $row['reversed']" />
            <x-ui.stat :label="__('Bank fees taken')" :value="$row['fees'].' '.$currency" />
            <x-ui.stat :label="__('Left short')" :value="$row['short'].' '.$currency" />
            <x-ui.stat :label="__('Kept over')" :value="$row['over'].' '.$currency" />
        </div>
    </x-ui.card>
@empty
    <x-ui.alert tone="info">{{ __('No statement imported yet.') }}</x-ui.alert>
@endforelse
@endsection
