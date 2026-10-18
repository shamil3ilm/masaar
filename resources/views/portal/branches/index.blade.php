@extends('layouts.portal')

@section('title', __('Branches'))

@section('content')
<x-ui.page-header :title="__('Branches')"
                  :description="__('Each branch is a till of its own: its own address on the invoice, its own numbering and printer, and the people who work there. Everyone sees one branch or all of them from the switcher above the menu.')">
    <x-slot:actions>
        <x-ui.button :href="route('portal.branches.create')">{{ __('New branch') }}</x-ui.button>
    </x-slot:actions>
</x-ui.page-header>

<div class="overflow-hidden rounded-card border border-line bg-surface shadow-card">
    <x-ui.table :primary="[0, 2, 3]">
        <x-slot:head>
            <x-ui.th>{{ __('Branch') }}</x-ui.th>
            <x-ui.th>{{ __('Till') }}</x-ui.th>
            <x-ui.th>{{ __('City') }}</x-ui.th>
            <x-ui.th>{{ __('Issuing') }}</x-ui.th>
            <x-ui.th>{{ __('People assigned') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Actions') }}</x-ui.th>
        </x-slot:head>

        @forelse ($branches as $branch)
            <tr class="hover:bg-sunken">
                <td class="px-6 py-4 text-sm">
                    <a href="{{ route('portal.branches.edit', $branch->id) }}" class="font-medium text-accent hover:underline">{{ $branch->name }}</a>
                    @if ($branch->name_ar)
                        <div class="text-muted" dir="rtl" lang="ar">{{ $branch->name_ar }}</div>
                    @endif
                    @if ($branch->is_default)
                        <div class="text-xs text-subtle">{{ __('Default branch') }}</div>
                    @endif
                </td>
                <td class="px-6 py-4 text-sm tabular-nums text-muted">{{ $branch->till_number ?? '—' }}</td>
                <td class="px-6 py-4 text-sm text-muted">{{ $branch->city ?: '—' }}</td>
                <td class="px-6 py-4 text-sm">
                    @if ($refusals[$branch->id] ?? null)
                        <x-ui.badge tone="warning" :label="__('Not yet')" />
                    @else
                        <x-ui.badge tone="success" :label="__('Ready')" />
                    @endif
                </td>
                <td class="px-6 py-4 text-sm tabular-nums text-muted">{{ $people[$branch->id] ?? 0 }}</td>
                <td class="px-6 py-4 text-end text-sm">
                    <a href="{{ route('portal.branches.edit', $branch->id) }}" class="text-accent hover:underline">{{ __('Edit') }}</a>
                </td>
            </tr>
        @empty
            <x-ui.empty :columns="6">{{ __('No branches yet. Until you add one, every invoice is issued from head office.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>
@endsection
