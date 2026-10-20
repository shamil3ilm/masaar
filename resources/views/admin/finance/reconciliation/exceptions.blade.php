@extends('layouts.admin')

@section('title', __('Unmatched, short and over'))

@section('content')
<x-ui.page-header :title="__('Unmatched, short and over')" :description="__('Money in that nothing is confirmed for yet, and confirmed matches that left invoices part paid or paid more than they owed.')" />

@include('admin.finance.reconciliation.nav', ['current' => 'exceptions'])

<x-ui.card class="mb-6" :title="__('Unmatched money in')" :description="__('The oldest first. A suggested line waits for an admin to confirm or reject it.')" flush>
    <x-ui.table :primary="[0, 1, 2]">
        <x-slot:head>
            <x-ui.th>{{ __('Date') }}</x-ui.th>
            <x-ui.th>{{ __('Reference') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Amount') }}</x-ui.th>
            <x-ui.th>{{ __('Status') }}</x-ui.th>
            <x-ui.th>{{ __('Payer') }}</x-ui.th>
        </x-slot:head>
        @forelse ($unmatched as $line)
            <tr class="hover:bg-sunken">
                <td class="px-6 py-4 text-sm text-fg"><x-ui.date :value="$line->booked_on" /></td>
                <td class="px-6 py-4 text-sm">
                    <a href="{{ route('admin.finance.reconciliation.line', $line->id) }}" class="text-accent hover:underline" dir="ltr">{{ $line->reference ?? $line->bank_reference ?? __('No reference') }}</a>
                    <span class="block max-w-md truncate text-xs text-muted">{{ $line->description }}</span>
                </td>
                <td class="px-6 py-4 text-right text-sm text-fg"><x-ui.amount :value="$line->amount" :currency="$line->currency" /></td>
                <td class="px-6 py-4"><x-ui.badge :tone="$line->status->tone()" :label="$line->status->label()" /></td>
                <td class="px-6 py-4 text-sm text-muted">{{ $line->counterparty_name }}</td>
            </tr>
        @empty
            <x-ui.empty :columns="5">{{ __('Every line of money in is matched or set aside.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</x-ui.card>
{{ $unmatched->links() }}

@foreach (['short' => [$short, __('Short'), __('Confirmed matches that left the invoices part paid: the customer still owes the rest.'), __('Nothing was matched short.')], 'over' => [$over, __('Over'), __('Confirmed matches for more than the invoices owed: the rest is the customer\'s credit.'), __('Nothing was matched over.')]] as $kind => [$rows, $title, $description, $empty])
    <x-ui.card class="mt-6" :title="$title" :description="$description" flush>
        <x-ui.table :primary="[0, 1, 3]">
            <x-slot:head>
                <x-ui.th>{{ __('Confirmed') }}</x-ui.th>
                <x-ui.th>{{ __('Customer') }}</x-ui.th>
                <x-ui.th>{{ __('Matched to') }}</x-ui.th>
                <x-ui.th align="right">{{ $kind === 'short' ? __('Short by') : __('Over by') }}</x-ui.th>
            </x-slot:head>
            @forelse ($rows as $row)
                @php($match = $row['match'])
                <tr class="hover:bg-sunken">
                    <td class="px-6 py-4 text-sm text-fg"><x-ui.date :value="$match->confirmed_at" /></td>
                    <td class="px-6 py-4 text-sm text-fg">{{ $row['customer'] }}</td>
                    <td class="px-6 py-4 text-sm text-muted" dir="ltr">{{ collect($row['targets'])->where('kind', '!=', 'created')->pluck('label')->implode(', ') }}</td>
                    <td class="px-6 py-4 text-right text-sm text-fg">
                        <x-ui.amount :value="$kind === 'short' ? abs((float) $match->difference + (float) $match->fee_amount) : $match->difference" :currency="$match->currency" />
                    </td>
                </tr>
            @empty
                <x-ui.empty :columns="4">{{ $empty }}</x-ui.empty>
            @endforelse
        </x-ui.table>
    </x-ui.card>
@endforeach
@endsection
