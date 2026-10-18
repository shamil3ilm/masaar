@extends('layouts.portal')

@section('title', __('Late reporting'))

{{--
    Simplified invoices not reported to ZATCA within twenty-four hours of
    issue, and the notices ZATCA's Detailed Guidelines for E-Invoicing ask of
    the seller (p48): to ZATCA through its website form when an invoice is
    late, and again once it is reported (PortalLateReportingController).

    @param \Illuminate\Support\Collection $documents  LateReporting::for
--}}

@section('content')
<x-ui.page-header :title="__('Late reporting')"
                  :description="__('Simplified invoices that could not be reported to ZATCA within 24 hours of being issued. The platform keeps trying at regular intervals and keeps a record of every attempt.')">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('ZATCA'), 'href' => route('portal.zatca.show')], ['label' => __('Late reporting')]]" />
    </x-slot:breadcrumb>
    <x-slot:actions>
        <x-ui.button variant="secondary" :href="route('portal.zatca.late.evidence')">{{ __('Evidence of attempts (CSV)') }}</x-ui.button>
    </x-slot:actions>
</x-ui.page-header>

<x-ui.alert tone="warning" class="mb-6">
    <p>{{ __('ZATCA asks a seller who cannot report an invoice within 24 hours to notify it through a dedicated form on its website, and to notify it again once the invoice is reported. Notify ZATCA on zatca.gov.sa, then record it here.') }}</p>
</x-ui.alert>

@php
    $awaitingNotice = $documents->whereNull('zatca_notified_at')->count();
    $awaitingRenotice = $documents->whereNotNull('reported_at')->whereNull('zatca_renotified_at')->count();
@endphp

@if ($awaitingNotice > 0 || $awaitingRenotice > 0)
    <div class="mb-6 flex flex-wrap gap-3">
        @if ($awaitingNotice > 0)
            <form method="POST" action="{{ route('portal.zatca.late.notified') }}"
                  data-confirm-action="{{ __('Record') }}" data-confirm="{{ __('Record that you notified ZATCA of the delay through its website form?') }}">
                @csrf
                <input type="hidden" name="notice" value="delay">
                <x-ui.button>{{ trans_choice('I notified ZATCA of the delay (one invoice)|I notified ZATCA of the delay (:count invoices)', $awaitingNotice, ['count' => $awaitingNotice]) }}</x-ui.button>
            </form>
        @endif
        @if ($awaitingRenotice > 0)
            <form method="POST" action="{{ route('portal.zatca.late.notified') }}"
                  data-confirm-action="{{ __('Record') }}" data-confirm="{{ __('Record that you notified ZATCA again, now that the invoices are reported?') }}">
                @csrf
                <input type="hidden" name="notice" value="resumed">
                <x-ui.button variant="secondary">{{ trans_choice('I notified ZATCA again (one invoice)|I notified ZATCA again (:count invoices)', $awaitingRenotice, ['count' => $awaitingRenotice]) }}</x-ui.button>
            </form>
        @endif
    </div>
@endif

<x-ui.card flush class="overflow-hidden">
        <x-ui.table :primary="[0, 2, 3]">
            <x-slot:head>
                <x-ui.th>{{ __('Invoice') }}</x-ui.th>
                <x-ui.th>{{ __('Issued') }}</x-ui.th>
                <x-ui.th>{{ __('Reported') }}</x-ui.th>
                <x-ui.th>{{ __('ZATCA notified') }}</x-ui.th>
                <x-ui.th>{{ __('Notified again') }}</x-ui.th>
            </x-slot:head>
            @foreach ($documents as $document)
                <tr>
                    <td class="px-6 py-3 text-sm font-medium text-fg" dir="ltr">{{ $document->invoice_number }}</td>
                    <td class="px-6 py-3 text-sm text-muted" dir="ltr">{{ $document->issued_at }} UTC</td>
                    <td class="px-6 py-3 text-sm">
                        @if ($document->reported_at)
                            <x-ui.badge tone="success" :label="__('Reported')" />
                        @else
                            <x-ui.badge tone="warning" :label="__('Still trying')" />
                        @endif
                    </td>
                    <td class="px-6 py-3 text-sm text-muted" dir="ltr">{{ $document->zatca_notified_at ?? '-' }}</td>
                    <td class="px-6 py-3 text-sm text-muted" dir="ltr">{{ $document->zatca_renotified_at ?? '-' }}</td>
                </tr>
            @endforeach
            @if ($documents->isEmpty())
                <x-ui.empty :columns="5">{{ __('Every simplified invoice has been reported within 24 hours.') }}</x-ui.empty>
            @endif
        </x-ui.table>
</x-ui.card>
@endsection
