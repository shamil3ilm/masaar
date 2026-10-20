@extends('layouts.admin')

@section('title', __('Statement').' - '.$organization->name)

@section('content')
<x-ui.page-header :title="__('Statement')" :description="__('Everything charged to :customer and everything it paid or was credited, with the balance after each line.', ['customer' => $organization->name])">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => 'Organizations', 'href' => route('admin.organizations')], ['label' => $organization->name, 'href' => route('admin.organization.detail', $organization->id)], ['label' => 'Billing', 'href' => route('admin.billing.show', $organization->id)], ['label' => __('Statement')]]" />
    </x-slot:breadcrumb>
    <x-slot:actions>
        <x-ui.button variant="secondary" :href="route('admin.billing.statement.csv', [$organization->id, 'currency' => $statement['currency'], 'from' => $statement['from'], 'to' => $statement['to']])">{{ __('Download CSV') }}</x-ui.button>
    </x-slot:actions>
</x-ui.page-header>

@include('portal.partials.billing-statement-period', ['action' => route('admin.billing.statement', $organization->id)])

<x-ui.card class="mb-6" flush>
    @include('portal.partials.billing-statement', ['statement' => $statement])
</x-ui.card>

@if ($ageing)
    <x-ui.card :title="__('Owed by age')" flush>
        @include('portal.partials.billing-ageing', ['table' => $ageing, 'headings' => $headings, 'currency' => $statement['currency'], 'customers' => false])
    </x-ui.card>
@endif
@endsection
