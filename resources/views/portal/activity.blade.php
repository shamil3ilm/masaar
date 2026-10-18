@extends('layouts.portal')

@section('title', __('Activity'))

@section('content')
<x-ui.page-header :title="__('Activity')"
                  :description="__('Who issued and corrected invoices, changed the company and its branches, and invited or removed whom - newest first. Read-only: nothing here can be changed.')" />

<x-ui.list-filters :list="$list" />

<div class="overflow-hidden rounded-card border border-line bg-surface shadow-card">
    <x-ui.table :primary="[2, 1, 0]">
        <x-slot:head>
            <x-ui.th :list="$list" sort="newest">{{ __('When') }}</x-ui.th>
            <x-ui.th>{{ __('Who') }}</x-ui.th>
            <x-ui.th>{{ __('What') }}</x-ui.th>
        </x-slot:head>

        @forelse ($entries as $entry)
            <tr class="hover:bg-sunken align-top">
                <td class="px-6 py-4 text-sm text-muted whitespace-nowrap">
                    <time datetime="{{ $entry['when']?->toIso8601String() }}" dir="ltr">{{ $entry['when']?->timezone(\App\Support\CountryClock::timeZone($organization->country))->format('Y-m-d H:i') }}</time>
                </td>
                <td class="px-6 py-4 text-sm">
                    @if ($entry['who'] || $entry['email'])
                        <div class="font-medium text-fg">{{ $entry['who'] ?? $entry['email'] }}</div>
                        @if ($entry['who'] && $entry['email'])
                            <div class="text-xs text-muted break-all">{{ $entry['email'] }}</div>
                        @endif
                    @else
                        <span class="text-muted">{{ __('The platform') }}</span>
                    @endif
                </td>
                <td class="px-6 py-4 text-sm">
                    <div class="text-fg">{{ $entry['summary'] }}</div>
                    <div class="font-mono text-xs text-subtle" dir="ltr">{{ $entry['action'] }}</div>
                </td>
            </tr>
        @empty
            <x-ui.empty :columns="3">{{ $list->filtered() ? __('Nothing recorded for these filters.') : __('Nothing recorded yet.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

{{ $entries->links() }}
@endsection
