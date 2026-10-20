@extends('layouts.portal')

@section('title', __('Schedules'))

@section('content')
<x-ui.page-header :title="__('Schedules')" :description="__('Invoices made again each period - a school\'s terms, a subscription, a monthly service - and instalment plans. Start one from an invoice you have written.')" />

<x-ui.list-filters :list="$list" />

<div class="bg-surface rounded-card border border-line shadow-card overflow-hidden">
    <x-ui.table :primary="[0, 2, 4]">
        <x-slot:head>
            <x-ui.th :list="$list" sort="name">{{ __('Schedule') }}</x-ui.th>
            <x-ui.th>{{ __('How often') }}</x-ui.th>
            <x-ui.th :list="$list" sort="next">{{ __('Next run') }}</x-ui.th>
            <x-ui.th>{{ __('Each run') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Status') }}</x-ui.th>
        </x-slot:head>
        @forelse ($schedules as $schedule)
        <tr class="hover:bg-sunken">
            <td class="px-6 py-4 text-sm">
                <a href="{{ route('portal.schedules.show', $schedule->id) }}" class="font-medium text-accent hover:underline">{{ $schedule->name }}</a>
                <div class="text-muted" dir="auto">{{ $schedule->buyerName() }}</div>
            </td>
            <td class="px-6 py-4 text-sm text-muted">
                {{ $schedule->frequency->label() }}
                @if ($schedule->instalment_model !== null)
                    <div class="text-xs">{{ __(':count instalments', ['count' => $schedule->occurrences ?? count((array) $schedule->terms)]) }}</div>
                @endif
            </td>
            <td class="px-6 py-4 text-sm tabular-nums text-fg"><x-ui.date :value="$schedule->next_run_on" /></td>
            <td class="px-6 py-4 text-sm text-muted">{{ $schedule->auto_issue ? __('Issued') : __('Draft for review') }}</td>
            <td class="px-6 py-4 text-end text-sm">
                <x-ui.badge :state="$schedule->status" />
                @if ($schedule->last_error)
                    <div class="mt-1 text-xs text-warning" title="{{ $schedule->last_error }}">{{ __('Last run failed') }}</div>
                @endif
            </td>
        </tr>
        @empty
        <x-ui.empty :columns="5">{{ $list->filtered() ? __('No schedule matches that filter.') : __('No schedules yet. Open an invoice and choose Repeat on a schedule.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

<div class="mt-4">
    {{ $schedules->links() }}
</div>
@endsection
