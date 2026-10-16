@extends('layouts.admin')

@section('title', 'Renewals')

@section('content')
<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Renewals</h2>
    <p class="text-gray-500 dark:text-gray-400">Plans ending in the next {{ $days }} days, and those that ended in the last {{ $days }}. Payment is recorded by hand, so these are the companies to ask before their invoicing stops.</p>
</div>

<div class="bg-white dark:bg-gray-900 rounded-lg shadow overflow-hidden">
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>Company</x-ui.th>
            <x-ui.th>Plan</x-ui.th>
            <x-ui.th>Ends</x-ui.th>
            <x-ui.th>Status</x-ui.th>
            <x-ui.th>Contact</x-ui.th>
            <x-ui.th align="right">Actions</x-ui.th>
        </x-slot:head>
        @forelse ($licences as $licence)
            @php
                $organization = $organizations[$licence->org_id] ?? null;
                $days = (int) round(now()->diffInDays($licence->expires_at, false));
            @endphp
        <tr class="hover:bg-gray-50 dark:hover:bg-gray-950">
            <td class="px-6 py-4 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $organization?->name ?? $licence->organization_name }}</td>
            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ $licence->tier->getDisplayName() }}</td>
            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                {{ $licence->expires_at->toDateString() }}
                <span class="block text-xs {{ $days < 0 ? 'text-red-700 dark:text-red-300' : ($days <= 7 ? 'text-amber-700 dark:text-amber-300' : 'text-gray-500 dark:text-gray-400') }}">
                    {{ $days < 0 ? 'ended '.abs($days).' days ago' : ($days === 0 ? 'ends today' : 'in '.$days.' days') }}
                </span>
            </td>
            <td class="px-6 py-4"><x-ui.badge :state="$licence->status->value" /></td>
            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300 break-all">{{ $licence->contact_email }}</td>
            <td class="px-6 py-4 text-right text-sm">
                @if ($organization)
                    <a href="{{ route('admin.billing.show', $organization->id) }}" class="text-teal-700 dark:text-teal-300 hover:underline">Plan and billing</a>
                @endif
            </td>
        </tr>
        @empty
        <x-ui.empty :columns="6">No plans end within {{ $days }} days either side of today</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>
@endsection
