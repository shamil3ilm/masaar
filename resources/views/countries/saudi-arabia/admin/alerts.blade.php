@extends('layouts.admin')

@section('title', 'Reporting deadlines')

@section('content')
<x-ui.page-header title="Reporting deadlines">
    <x-slot:description>
        ZATCA gives a simplified invoice {{ (int) $deadlineHours }} hours from issuance to be reported.
        The same rows <code class="text-xs">fatoora:overdue-submissions</code> alerts on, every hour.
    </x-slot:description>
</x-ui.page-header>

@if($overdue->isEmpty() && $atRisk->isEmpty())
    <div class="bg-surface rounded-card border border-line shadow-card px-6 py-10 text-center">
        <p class="text-lg font-medium text-fg">Nothing is overdue</p>
        <p class="mt-1 text-sm text-muted">
            No simplified submission is past {{ rtrim(rtrim(number_format($warningHours, 1), '0'), '.') }} hours
            without having reached the authority.
        </p>
    </div>
@else
    <div class="mb-8">
        <div class="mb-2 flex items-baseline justify-between">
            <h3 class="text-lg font-semibold text-danger">
                Overdue &mdash; past {{ (int) $deadlineHours }}h and not reported
            </h3>
            <span class="text-sm text-muted">
                {{ $overdueTotal }} {{ Str::plural('invoice', $overdueTotal) }}{{ $overdueTotal > $cappedAt ? ', showing the '.$cappedAt.' oldest' : '' }}
            </span>
        </div>
        <p class="mb-3 text-sm text-muted">The deadline has passed. What is left is to report late and to know which invoices were affected.</p>

        @include('countries.saudi-arabia.admin.alerts-table', ['rows' => $overdue, 'empty' => 'Nothing is past the deadline.'])
    </div>

    <div>
        <div class="mb-2 flex items-baseline justify-between">
            <h3 class="text-lg font-semibold text-warning">
                At risk &mdash; past {{ rtrim(rtrim(number_format($warningHours, 1), '0'), '.') }}h, deadline not yet passed
            </h3>
            <span class="text-sm text-muted">
                {{ $atRiskTotal }} {{ Str::plural('invoice', $atRiskTotal) }}{{ $atRiskTotal > $cappedAt ? ', showing the '.$cappedAt.' oldest' : '' }}
            </span>
        </div>
        <p class="mb-3 text-sm text-muted">Still recoverable &mdash; the only case where seeing it early changes the outcome.</p>

        @include('countries.saudi-arabia.admin.alerts-table', ['rows' => $atRisk, 'empty' => 'Nothing is approaching its deadline.'])
    </div>
@endif
@endsection
