@extends('layouts.admin')

@section('title', 'Organizations')

@section('content')
<x-ui.page-header title="Organizations" description="Every registered organization, and the ones waiting for review." class="mb-3" />
<div class="mb-6">
    <div class="flex gap-4 text-sm">
        <a href="{{ route('admin.organizations') }}" class="{{ $list->value('status') ? 'text-muted' : 'font-semibold text-accent' }} hover:underline">All</a>
        <a href="{{ route('admin.organizations', ['status' => 'pending_review']) }}" class="{{ $list->value('status') === 'pending_review' ? 'font-semibold text-accent' : 'text-muted' }} hover:underline">Awaiting review</a>
    </div>
</div>

<x-ui.list-filters :list="$list" />

<div class="bg-surface rounded-card border border-line shadow-card overflow-hidden">
    <x-ui.table :primary="[0, 1, 2]">
        <x-slot:head>
            <x-ui.th :list="$list" sort="name">Organization</x-ui.th>
            <x-ui.th>VAT Number</x-ui.th>
            <x-ui.th>Status</x-ui.th>
            <x-ui.th>Certificate</x-ui.th>
            <x-ui.th>Submissions</x-ui.th>
            <x-ui.th>Actions</x-ui.th>
        </x-slot:head>

        @forelse($organizations as $org)
        <tr class="hover:bg-sunken">
            <td class="px-6 py-4">
                <div class="font-medium text-fg">{{ $org->name }}</div>
                <div class="text-xs text-muted">{{ Str::limit($org->id, 8) }}</div>
            </td>
            <td class="px-6 py-4 text-sm text-muted">{{ $org->vat_number ?? '-' }}</td>
            <td class="px-6 py-4">
                <x-ui.badge :state="$org->status" />
            </td>
            <td class="px-6 py-4">
                @if($org->cert_expires_at)
                    @php
                        // Whole days: Carbon 3 returns a fraction of one.
                        $daysLeft = (int) floor(now()->diffInDays($org->cert_expires_at, false));
                        $expiryTone = $daysLeft <= 7 ? 'danger' : ($daysLeft <= 30 ? 'warning' : 'success');
                        $expiryLabel = $daysLeft > 0 ? __(':days days', ['days' => $daysLeft]) : __('Expired');
                    @endphp
                    <x-ui.badge :tone="$expiryTone" :label="$expiryLabel" />
                @else
                    <x-ui.badge label="No cert" />
                @endif
            </td>
            <td class="px-6 py-4 text-sm text-muted">
                {{ $submissionStats[$org->id] ?? 0 }} successful
            </td>
            <td class="px-6 py-4">
                <a href="{{ route('admin.organization.detail', $org->id) }}" class="text-accent hover:text-accent-strong text-sm">View</a>
            </td>
        </tr>
        @empty
        <x-ui.empty :columns="6">No organizations found</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

{{ $organizations->links() }}
@endsection
