@extends('layouts.portal')

@section('title', __('Statement'))

@section('content')
<x-ui.page-header :title="__('Statement')" :description="__('Everything we invoiced you and everything you paid or were credited, with the balance after each line.')">
    <x-slot:actions>
        <x-ui.button variant="secondary" :href="route('portal.billing.statement.csv', ['currency' => $statement['currency'], 'from' => $statement['from'], 'to' => $statement['to']])">{{ __('Download CSV') }}</x-ui.button>
        <x-ui.button variant="ghost" :href="route('portal.billing')">{{ __('Plan and billing') }}</x-ui.button>
    </x-slot:actions>
</x-ui.page-header>

@include('portal.partials.billing-statement-period', ['action' => route('portal.billing.statement')])

<div class="mb-6 bg-surface rounded-card border border-line shadow-card overflow-hidden">
    @include('portal.partials.billing-statement', ['statement' => $statement])
</div>

@if ($ageing)
    <h2 class="mb-3 text-lg font-semibold text-fg">{{ __('Owed by age') }}</h2>
    <div class="mb-6 bg-surface rounded-card border border-line shadow-card overflow-hidden">
        @include('portal.partials.billing-ageing', ['table' => $ageing, 'headings' => $headings, 'currency' => $statement['currency'], 'customers' => false])
    </div>
@endif

@if ($accounts !== [])
    <x-ui.card :title="__('Paying by bank transfer')" :description="__('Quote the invoice number as the transfer reference.')">
        <ul class="space-y-2 text-sm">
            @foreach ($accounts as $bank)
                <li><span class="font-medium text-fg">{{ $bank['bank_name'] }}</span> · {{ $bank['account_name'] }} · <span class="font-mono" dir="ltr">{{ \App\Domains\Billing\Settings\Iban::format($bank['iban']) }}</span>@if ($bank['swift'] !== '') · <span class="font-mono" dir="ltr">{{ $bank['swift'] }}</span>@endif</li>
            @endforeach
        </ul>
    </x-ui.card>
@endif
@endsection
