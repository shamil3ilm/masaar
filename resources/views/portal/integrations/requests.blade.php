@extends('layouts.portal')

@section('title', __('Request log'))

@php
    $zone = \App\Support\CountryClock::timeZone($organization->country);
@endphp

@section('content')
<x-ui.page-header :title="__('Integrations')"
                  :description="__('Every call your systems made to the API in the last :days days: what was asked, how it was answered and with which key. Read-only, and never a request body or a secret.', ['days' => $days])" />

@include('portal.integrations.partials.nav')

<x-ui.list-filters :list="$list" />

<div class="overflow-hidden rounded-card border border-line bg-surface shadow-card">
    <x-ui.table :primary="[0, 1, 2]">
        <x-slot:head>
            <x-ui.th :list="$list" sort="newest">{{ __('When') }}</x-ui.th>
            <x-ui.th>{{ __('Request') }}</x-ui.th>
            <x-ui.th>{{ __('Answer') }}</x-ui.th>
            <x-ui.th>{{ __('Key') }}</x-ui.th>
            <x-ui.th>{{ __('Request id') }}</x-ui.th>
        </x-slot:head>

        @forelse ($requests as $entry)
            <tr class="align-top" data-request-log>
                <td class="px-4 py-3 text-xs text-muted whitespace-nowrap"><x-ui.date :value="$entry->occurred_at" time seconds :zone="$zone" /></td>
                <td class="px-4 py-3 font-mono text-xs text-fg break-all" dir="ltr">{{ $entry->method }} {{ $entry->path }}</td>
                <td class="px-4 py-3 text-sm">
                    <x-ui.badge :tone="$entry->status >= 500 ? 'warning' : ($entry->status >= 400 ? 'danger' : 'success')" :label="(string) $entry->status" />
                    @if ($entry->error_code)
                        <div class="mt-1 font-mono text-xs text-muted" dir="ltr">{{ $entry->error_code }}</div>
                    @endif
                    <div class="mt-1 text-xs text-subtle tabular-nums">{{ __(':ms ms', ['ms' => $entry->duration_ms]) }}</div>
                </td>
                <td class="px-4 py-3 text-xs text-muted">{{ $keys[$entry->api_key_id ?? \App\Domains\Licensing\Services\RequestLog::LICENCE_KEY] ?? __('A key since removed') }}</td>
                <td class="px-4 py-3 font-mono text-xs text-subtle break-all" dir="ltr">{{ $entry->request_id }}</td>
            </tr>
        @empty
            <x-ui.empty :columns="5">{{ $list->filtered() ? __('No requests for these filters.') : __('No requests yet.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

{{ $requests->links() }}
@endsection
