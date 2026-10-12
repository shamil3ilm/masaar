@extends('layouts.admin')

@section('title', 'Reporting Deadlines - Masaar Admin')

@section('content')
<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-800">Reporting deadlines</h2>
    <p class="text-gray-500">
        ZATCA gives a simplified invoice {{ (int) $deadlineHours }} hours from issuance to be reported.
        The same rows <code class="text-xs">fatoora:overdue-submissions</code> alerts on, every hour.
    </p>
</div>

@if($overdue->isEmpty() && $atRisk->isEmpty())
    <div class="bg-white rounded-lg shadow px-6 py-10 text-center">
        <p class="text-lg font-medium text-gray-800">Nothing is overdue</p>
        <p class="mt-1 text-sm text-gray-500">
            No simplified submission is past {{ rtrim(rtrim(number_format($warningHours, 1), '0'), '.') }} hours
            without having reached the authority.
        </p>
    </div>
@else
    <div class="mb-8">
        <div class="mb-2 flex items-baseline justify-between">
            <h3 class="text-lg font-semibold text-red-700">
                Overdue &mdash; past {{ (int) $deadlineHours }}h and not reported
            </h3>
            <span class="text-sm text-gray-500">{{ $overdue->count() }} {{ Str::plural('invoice', $overdue->count()) }}</span>
        </div>
        <p class="mb-3 text-sm text-gray-500">The deadline has passed. What is left is to report late and to know which invoices were affected.</p>

        @include('admin.alerts.table', ['rows' => $overdue, 'empty' => 'Nothing is past the deadline.'])
    </div>

    <div>
        <div class="mb-2 flex items-baseline justify-between">
            <h3 class="text-lg font-semibold text-yellow-700">
                At risk &mdash; past {{ rtrim(rtrim(number_format($warningHours, 1), '0'), '.') }}h, deadline not yet passed
            </h3>
            <span class="text-sm text-gray-500">{{ $atRisk->count() }} {{ Str::plural('invoice', $atRisk->count()) }}</span>
        </div>
        <p class="mb-3 text-sm text-gray-500">Still recoverable &mdash; the only case where seeing it early changes the outcome.</p>

        @include('admin.alerts.table', ['rows' => $atRisk, 'empty' => 'Nothing is approaching its deadline.'])
    </div>
@endif
@endsection
