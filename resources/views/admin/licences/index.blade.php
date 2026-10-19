@extends('layouts.admin')

@section('title', 'Licences')

@section('content')
<x-ui.page-header title="Licences" description="Partner API keys, their tier and their quota.">
    @admincan('licences.manage')
    <x-slot:actions>
        <x-ui.button :href="route('admin.licences.create')">Issue licence</x-ui.button>
    </x-slot:actions>
    @endadmincan
</x-ui.page-header>

<x-ui.list-filters :list="$list" />

<div class="bg-surface rounded-card border border-line shadow-card overflow-hidden">
    <x-ui.table :primary="[0, 2, 1]">
        <x-slot:head>
            <x-ui.th :list="$list" sort="organization">Organization</x-ui.th>
            <x-ui.th>Tier</x-ui.th>
            <x-ui.th>Status</x-ui.th>
            <x-ui.th :list="$list" sort="quota">Invoices / month</x-ui.th>
            <x-ui.th :list="$list" sort="expires">Expires</x-ui.th>
            <x-ui.th align="right">Actions</x-ui.th>
        </x-slot:head>

        @forelse($licences as $licence)
        <tr class="hover:bg-sunken">
            <td class="px-6 py-4">
                <a href="{{ route('admin.licences.show', $licence->id) }}" class="font-medium text-accent hover:text-accent-strong">
                    {{ $licence->organization_name }}
                </a>
                <div class="text-xs text-muted">{{ $licence->contact_email }}</div>
            </td>
            <td class="px-6 py-4 text-sm text-muted">{{ $licence->tier->getDisplayName() }}</td>
            <td class="px-6 py-4">
                <x-ui.badge :state="$licence->status" :label="$licence->status->getLabel()" />
            </td>
            <td class="px-6 py-4 text-sm text-muted">{{ number_format($licence->invoices_per_month) }}</td>
            <td class="px-6 py-4 text-sm text-muted">
                {{ $licence->expires_at ? $licence->expires_at->toDateString() : 'Never' }}
            </td>
            <td class="px-6 py-4 text-right text-sm">
                <a href="{{ route('admin.licences.show', $licence->id) }}" class="text-accent hover:text-accent-strong">Manage</a>
            </td>
        </tr>
        @empty
        <x-ui.empty :columns="6">No licences match these filters</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

{{ $licences->links() }}
@endsection
