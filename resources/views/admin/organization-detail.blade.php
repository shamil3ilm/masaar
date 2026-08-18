@extends('layouts.admin')

@section('title', $organization->name . ' - Masaar Admin')

@section('content')
<div class="mb-6">
    <div class="flex items-center space-x-2 text-sm text-gray-500 mb-2">
        <a href="{{ route('admin.organizations') }}" class="hover:text-blue-600">Organizations</a>
        <span>/</span>
        <span>{{ $organization->name }}</span>
    </div>
    <h2 class="text-2xl font-bold text-gray-800">{{ $organization->name }}</h2>
</div>

<!-- Stats Cards -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
    <div class="bg-white rounded-lg shadow p-6">
        <div class="text-sm font-medium text-gray-500">Total Invoices</div>
        <div class="text-3xl font-bold text-gray-900 mt-2">{{ number_format($stats['invoices']) }}</div>
    </div>
    <div class="bg-white rounded-lg shadow p-6">
        <div class="text-sm font-medium text-gray-500">Total Submissions</div>
        <div class="text-3xl font-bold text-gray-900 mt-2">{{ number_format($stats['submissions']) }}</div>
    </div>
    <div class="bg-white rounded-lg shadow p-6">
        <div class="text-sm font-medium text-gray-500">Cleared</div>
        <div class="text-3xl font-bold text-green-600 mt-2">{{ number_format($stats['cleared']) }}</div>
    </div>
    <div class="bg-white rounded-lg shadow p-6">
        <div class="text-sm font-medium text-gray-500">Rejected</div>
        <div class="text-3xl font-bold text-red-600 mt-2">{{ number_format($stats['rejected']) }}</div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    <!-- Organization Details -->
    <div class="lg:col-span-2 bg-white rounded-lg shadow">
        <div class="p-6 border-b">
            <h3 class="text-lg font-semibold text-gray-800">Organization Details</h3>
        </div>
        <div class="p-6">
            <dl class="grid grid-cols-2 gap-4">
                <div>
                    <dt class="text-sm font-medium text-gray-500">ID</dt>
                    <dd class="text-sm text-gray-900 font-mono mt-1">{{ $organization->id }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500">Status</dt>
                    <dd class="mt-1">
                        <span class="px-2 py-1 text-xs font-medium rounded-full {{ $organization->status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}">
                            {{ $organization->status ?? 'unknown' }}
                        </span>
                    </dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500">VAT Number</dt>
                    <dd class="text-sm text-gray-900 mt-1">{{ $organization->vat_number ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500">CR Number</dt>
                    <dd class="text-sm text-gray-900 mt-1">{{ $organization->cr_number ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500">City</dt>
                    <dd class="text-sm text-gray-900 mt-1">{{ $organization->city ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500">Country</dt>
                    <dd class="text-sm text-gray-900 mt-1">{{ $organization->country ?? 'SA' }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500">Created</dt>
                    <dd class="text-sm text-gray-900 mt-1">{{ $organization->created_at ? \Carbon\Carbon::parse($organization->created_at)->format('M d, Y H:i') : '-' }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500">Updated</dt>
                    <dd class="text-sm text-gray-900 mt-1">{{ $organization->updated_at ? \Carbon\Carbon::parse($organization->updated_at)->format('M d, Y H:i') : '-' }}</dd>
                </div>
            </dl>
        </div>
    </div>

    <!-- Certificate Status -->
    <div class="bg-white rounded-lg shadow">
        <div class="p-6 border-b">
            <h3 class="text-lg font-semibold text-gray-800">Certificate Status</h3>
        </div>
        <div class="p-6">
            @if($certificate)
                @php
                    $daysLeft = $certificate->valid_to ? now()->diffInDays($certificate->valid_to, false) : null;
                @endphp
                <div class="text-center mb-4">
                    @if($daysLeft !== null && $daysLeft > 0)
                        <div class="inline-flex items-center justify-center w-20 h-20 rounded-full {{ $daysLeft <= 7 ? 'bg-red-100' : ($daysLeft <= 30 ? 'bg-yellow-100' : 'bg-green-100') }}">
                            <span class="text-2xl font-bold {{ $daysLeft <= 7 ? 'text-red-600' : ($daysLeft <= 30 ? 'text-yellow-600' : 'text-green-600') }}">{{ $daysLeft }}</span>
                        </div>
                        <p class="text-sm text-gray-500 mt-2">days until expiry</p>
                    @else
                        <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-red-100">
                            <svg class="w-10 h-10 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </div>
                        <p class="text-sm text-red-600 mt-2 font-medium">Certificate Expired</p>
                    @endif
                </div>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Serial</dt>
                        <dd class="text-gray-900 font-mono text-xs">{{ Str::limit($certificate->serial_number ?? '-', 16) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Valid From</dt>
                        <dd class="text-gray-900">{{ $certificate->valid_from ? \Carbon\Carbon::parse($certificate->valid_from)->format('M d, Y') : '-' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Valid To</dt>
                        <dd class="text-gray-900">{{ $certificate->valid_to ? \Carbon\Carbon::parse($certificate->valid_to)->format('M d, Y') : '-' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Status</dt>
                        <dd>
                            <span class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-800">{{ $certificate->status }}</span>
                        </dd>
                    </div>
                </dl>
            @else
                <div class="text-center py-8">
                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    <p class="mt-2 text-sm text-gray-500">No active certificate</p>
                </div>
            @endif
        </div>
    </div>
</div>

<!-- Recent Submissions -->
<div class="bg-white rounded-lg shadow">
    <div class="p-6 border-b flex justify-between items-center">
        <h3 class="text-lg font-semibold text-gray-800">Recent Submissions</h3>
        <a href="{{ route('admin.logs', ['org_id' => $organization->id]) }}" class="text-sm text-blue-600 hover:text-blue-800">View All</a>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice ID</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">State</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Submission Type</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Created</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($recentSubmissions as $submission)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 text-sm font-mono text-gray-900">{{ Str::limit($submission->invoice_id, 12) }}</td>
                    <td class="px-6 py-4">
                        @php
                            $stateColors = [
                                'cleared' => 'bg-green-100 text-green-800',
                                'reported' => 'bg-blue-100 text-blue-800',
                                'rejected' => 'bg-red-100 text-red-800',
                                'pending_submission' => 'bg-yellow-100 text-yellow-800',
                                'submitted' => 'bg-indigo-100 text-indigo-800',
                                'queued' => 'bg-gray-100 text-gray-800',
                            ];
                            $colorClass = $stateColors[$submission->state] ?? 'bg-gray-100 text-gray-800';
                        @endphp
                        <span class="px-2 py-1 text-xs font-medium rounded-full {{ $colorClass }}">
                            {{ $submission->state ?? 'unknown' }}
                        </span>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $submission->submission_type ?? '-' }}</td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $submission->created_at ? \Carbon\Carbon::parse($submission->created_at)->format('M d, Y H:i') : '-' }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="4" class="px-6 py-4 text-center text-gray-500">No submissions found</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
