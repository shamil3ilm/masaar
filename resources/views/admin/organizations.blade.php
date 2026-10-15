@extends('layouts.admin')

@section('title', 'Organizations - Masaar Admin')

@section('content')
<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Organizations</h2>
    <p class="text-gray-500 dark:text-gray-400">Manage all registered organizations</p>
</div>

<div class="bg-white dark:bg-gray-900 rounded-lg shadow overflow-hidden">
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>Organization</x-ui.th>
            <x-ui.th>VAT Number</x-ui.th>
            <x-ui.th>Status</x-ui.th>
            <x-ui.th>Certificate</x-ui.th>
            <x-ui.th>Submissions</x-ui.th>
            <x-ui.th>Actions</x-ui.th>
        </x-slot:head>

        @forelse($organizations as $org)
        <tr class="hover:bg-gray-50 dark:hover:bg-gray-950">
            <td class="px-6 py-4">
                <div class="font-medium text-gray-900 dark:text-gray-100">{{ $org->name }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ Str::limit($org->id, 8) }}</div>
            </td>
            <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $org->vat_number ?? '-' }}</td>
            <td class="px-6 py-4">
                <x-ui.badge :state="$org->status" />
            </td>
            <td class="px-6 py-4">
                @if($org->cert_expires_at)
                    @php
                        $daysLeft = now()->diffInDays($org->cert_expires_at, false);
                        $expiryTone = $daysLeft <= 7 ? 'danger' : ($daysLeft <= 30 ? 'warning' : 'success');
                        $expiryLabel = $daysLeft > 0 ? $daysLeft.' days' : 'Expired';
                    @endphp
                    <x-ui.badge :tone="$expiryTone" :label="$expiryLabel" />
                @else
                    <x-ui.badge label="No cert" />
                @endif
            </td>
            <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                {{ $submissionStats[$org->id] ?? 0 }} successful
            </td>
            <td class="px-6 py-4">
                <a href="{{ route('admin.organization.detail', $org->id) }}" class="text-teal-700 dark:text-teal-300 hover:text-teal-900 dark:hover:text-teal-200 text-sm">View</a>
            </td>
        </tr>
        @empty
        <x-ui.empty :columns="6">No organizations found</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

<div class="mt-4">
    {{ $organizations->appends(request()->query())->links() }}
</div>
@endsection
