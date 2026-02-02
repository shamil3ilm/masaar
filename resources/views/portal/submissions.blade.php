@extends('layouts.portal')

@section('title', 'Submissions - ' . $organization->name)

@section('content')
<div class="mb-8">
    <h2 class="text-2xl font-bold text-gray-800">Submissions</h2>
    <p class="text-gray-500">ZATCA invoice submission history</p>
</div>

<!-- Stats -->
<div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
    <a href="{{ route('portal.submissions', ['org_id' => request('org_id')]) }}"
       class="bg-white rounded-lg shadow p-4 text-center hover:bg-gray-50 {{ !$state ? 'ring-2 ring-blue-500' : '' }}">
        <p class="text-2xl font-bold">{{ array_sum($stateCounts->toArray()) }}</p>
        <p class="text-xs text-gray-500">All</p>
    </a>
    <a href="{{ route('portal.submissions', ['org_id' => request('org_id'), 'state' => 'cleared']) }}"
       class="bg-white rounded-lg shadow p-4 text-center hover:bg-gray-50 {{ $state === 'cleared' ? 'ring-2 ring-green-500' : '' }}">
        <p class="text-2xl font-bold text-green-600">{{ $stateCounts['cleared'] ?? 0 }}</p>
        <p class="text-xs text-gray-500">Cleared</p>
    </a>
    <a href="{{ route('portal.submissions', ['org_id' => request('org_id'), 'state' => 'reported']) }}"
       class="bg-white rounded-lg shadow p-4 text-center hover:bg-gray-50 {{ $state === 'reported' ? 'ring-2 ring-blue-500' : '' }}">
        <p class="text-2xl font-bold text-blue-600">{{ $stateCounts['reported'] ?? 0 }}</p>
        <p class="text-xs text-gray-500">Reported</p>
    </a>
    <a href="{{ route('portal.submissions', ['org_id' => request('org_id'), 'state' => 'rejected']) }}"
       class="bg-white rounded-lg shadow p-4 text-center hover:bg-gray-50 {{ $state === 'rejected' ? 'ring-2 ring-red-500' : '' }}">
        <p class="text-2xl font-bold text-red-600">{{ $stateCounts['rejected'] ?? 0 }}</p>
        <p class="text-xs text-gray-500">Rejected</p>
    </a>
    <a href="{{ route('portal.submissions', ['org_id' => request('org_id'), 'state' => 'failed']) }}"
       class="bg-white rounded-lg shadow p-4 text-center hover:bg-gray-50 {{ $state === 'failed' ? 'ring-2 ring-orange-500' : '' }}">
        <p class="text-2xl font-bold text-orange-600">{{ $stateCounts['failed'] ?? 0 }}</p>
        <p class="text-xs text-gray-500">Failed</p>
    </a>
</div>

<!-- Filters -->
<div class="bg-white rounded-lg shadow p-4 mb-6">
    <form method="GET" class="flex flex-wrap gap-4 items-end">
        <input type="hidden" name="org_id" value="{{ request('org_id') }}">
        <input type="hidden" name="state" value="{{ $state }}">

        <div class="flex-1 min-w-[200px]">
            <label class="block text-xs text-gray-500 mb-1">User</label>
            <select name="user_id" class="w-full border rounded px-3 py-2 text-sm">
                <option value="">All Users</option>
                @foreach($users as $user)
                <option value="{{ $user->id }}" {{ $userId == $user->id ? 'selected' : '' }}>
                    {{ $user->name ?? $user->email }}
                </option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-xs text-gray-500 mb-1">From</label>
            <input type="date" name="date_from" value="{{ $dateFrom }}" class="border rounded px-3 py-2 text-sm">
        </div>

        <div>
            <label class="block text-xs text-gray-500 mb-1">To</label>
            <input type="date" name="date_to" value="{{ $dateTo }}" class="border rounded px-3 py-2 text-sm">
        </div>

        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded text-sm hover:bg-blue-700">Filter</button>
        <a href="{{ route('portal.submissions', ['org_id' => request('org_id')]) }}"
           class="px-4 py-2 bg-gray-200 text-gray-700 rounded text-sm hover:bg-gray-300">Clear</a>
    </form>
</div>

<!-- Submissions Table -->
<div class="bg-white rounded-lg shadow overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">User</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">State</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Error</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($submissions as $sub)
            <tr class="hover:bg-gray-50">
                <td class="px-6 py-4">
                    <p class="text-sm font-medium text-gray-800">{{ $sub->invoice_number ?? Str::limit($sub->invoice_id, 8) }}</p>
                    <p class="text-xs text-gray-500">{{ Str::limit($sub->id, 8) }}</p>
                </td>
                <td class="px-6 py-4">
                    @if($sub->user_name || $sub->user_email)
                    <a href="{{ route('portal.user.activity', ['userId' => $sub->created_by, 'org_id' => request('org_id')]) }}"
                       class="text-sm text-blue-600 hover:text-blue-800">
                        {{ $sub->user_name ?? $sub->user_email }}
                    </a>
                    @else
                    <span class="text-sm text-gray-400">System</span>
                    @endif
                </td>
                <td class="px-6 py-4">
                    <span class="px-2 py-1 text-xs font-medium rounded bg-gray-100 text-gray-700">
                        {{ $sub->submission_type ?? '-' }}
                    </span>
                </td>
                <td class="px-6 py-4">
                    <span class="px-2 py-1 text-xs font-medium rounded-full
                        {{ $sub->state === 'cleared' ? 'bg-green-100 text-green-800' : '' }}
                        {{ $sub->state === 'reported' ? 'bg-blue-100 text-blue-800' : '' }}
                        {{ $sub->state === 'rejected' ? 'bg-red-100 text-red-800' : '' }}
                        {{ $sub->state === 'failed' ? 'bg-orange-100 text-orange-800' : '' }}
                        {{ in_array($sub->state, ['pending', 'queued', 'submitted']) ? 'bg-yellow-100 text-yellow-800' : '' }}">
                        {{ $sub->state }}
                    </span>
                </td>
                <td class="px-6 py-4 text-sm text-gray-600">
                    @if($sub->invoice_total)
                    {{ number_format($sub->invoice_total, 2) }} SAR
                    @else
                    -
                    @endif
                </td>
                <td class="px-6 py-4 text-sm text-gray-500">
                    {{ \Carbon\Carbon::parse($sub->created_at)->format('M d, H:i') }}
                </td>
                <td class="px-6 py-4">
                    @if($sub->last_error_message)
                    <span class="text-xs text-red-600" title="{{ $sub->last_error_message }}">
                        {{ Str::limit($sub->last_error_message, 25) }}
                    </span>
                    @else
                    <span class="text-gray-400">-</span>
                    @endif
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="7" class="px-6 py-8 text-center text-gray-500">No submissions found</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">
    {{ $submissions->appends(request()->query())->links() }}
</div>
@endsection
