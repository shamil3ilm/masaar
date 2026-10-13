@extends('layouts.portal')

@section('title', ($user->name ?? $user->email) . ' Activity')

@section('content')
<div class="mb-8">
    <a href="{{ route('portal.submissions', ['org_id' => request('org_id')]) }}"
       class="text-teal-700 hover:text-teal-900 text-sm mb-2 inline-block">&larr; Back to Submissions</a>
    <div class="flex items-center">
        <div class="w-16 h-16 bg-teal-100 rounded-full flex items-center justify-center mr-4">
            <span class="text-teal-700 font-bold text-xl">{{ strtoupper(substr($user->name ?? $user->email, 0, 2)) }}</span>
        </div>
        <div>
            <h2 class="text-2xl font-bold text-gray-800">{{ $user->name ?? 'User' }}</h2>
            <p class="text-gray-500">{{ $user->email }}</p>
        </div>
    </div>
</div>

<!-- User Stats -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <div class="bg-white rounded-lg shadow p-6">
        <p class="text-sm text-gray-500">Today</p>
        <p class="text-3xl font-bold text-gray-800">{{ $userStats['today'] }}</p>
    </div>
    <div class="bg-white rounded-lg shadow p-6">
        <p class="text-sm text-gray-500">Total Submissions</p>
        <p class="text-3xl font-bold text-gray-800">{{ $userStats['total'] }}</p>
    </div>
    <div class="bg-white rounded-lg shadow p-6">
        <p class="text-sm text-gray-500">Cleared</p>
        <p class="text-3xl font-bold text-green-600">{{ $userStats['cleared'] }}</p>
    </div>
    <div class="bg-white rounded-lg shadow p-6">
        <p class="text-sm text-gray-500">Rejected</p>
        <p class="text-3xl font-bold {{ $userStats['rejected'] > 0 ? 'text-red-600' : 'text-gray-400' }}">{{ $userStats['rejected'] }}</p>
    </div>
</div>

<!-- User's Submissions -->
<div class="bg-white rounded-lg shadow">
    <div class="p-6 border-b">
        <h3 class="text-lg font-semibold text-gray-800">Submission History</h3>
    </div>
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>Invoice</x-ui.th>
            <x-ui.th>Type</x-ui.th>
            <x-ui.th>State</x-ui.th>
            <x-ui.th>Amount</x-ui.th>
            <x-ui.th>Date</x-ui.th>
            <x-ui.th>Error</x-ui.th>
        </x-slot:head>

        @forelse($submissions as $sub)
        <tr class="hover:bg-gray-50">
            <td class="px-6 py-4">
                <p class="text-sm font-medium text-gray-800">{{ $sub->invoice_number ?? Str::limit($sub->invoice_id, 8) }}</p>
            </td>
            <td class="px-6 py-4">
                <x-ui.badge tone="neutral" :label="ucfirst($sub->submission_type ?? '-')" />
            </td>
            <td class="px-6 py-4">
                <x-ui.badge :state="$sub->state" />
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
                @if($sub->last_error)
                <span class="text-xs text-red-600" title="{{ $sub->last_error }}">
                    {{ Str::limit($sub->last_error, 30) }}
                </span>
                @else
                <span class="text-gray-400">-</span>
                @endif
            </td>
        </tr>
        @empty
        <x-ui.empty :columns="6">No submissions by this user</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

<div class="mt-4">
    {{ $submissions->appends(request()->query())->links() }}
</div>
@endsection
