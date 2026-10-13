@extends('layouts.admin')

@section('title', 'Organizations - Masaar Admin')

@section('content')
<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-800">Organizations</h2>
    <p class="text-gray-500">Manage all registered organizations</p>
</div>

<div class="bg-white rounded-lg shadow overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Organization</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">VAT Number</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Certificate</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Submissions</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($organizations as $org)
            <tr class="hover:bg-gray-50">
                <td class="px-6 py-4">
                    <div class="font-medium text-gray-900">{{ $org->name }}</div>
                    <div class="text-xs text-gray-500">{{ Str::limit($org->id, 8) }}</div>
                </td>
                <td class="px-6 py-4 text-sm text-gray-500">{{ $org->vat_number ?? '-' }}</td>
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
                <td class="px-6 py-4 text-sm text-gray-500">
                    {{ $submissionStats[$org->id] ?? 0 }} successful
                </td>
                <td class="px-6 py-4">
                    <a href="{{ route('admin.organization.detail', $org->id) }}" class="text-teal-700 hover:text-teal-900 text-sm">View</a>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="6" class="px-6 py-4 text-center text-gray-500">No organizations found</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">
    {{ $organizations->links() }}
</div>
@endsection
