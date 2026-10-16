@extends('layouts.portal')

@section('title', __(':name Activity', ['name' => $user->name ?? $user->email]))

@section('content')
<div class="mb-8">
    <a href="{{ route('portal.submissions', ['org_id' => request('org_id')]) }}"
       class="text-teal-700 dark:text-teal-300 hover:text-teal-900 dark:hover:text-teal-200 text-sm mb-2 inline-block"><span class="inline-block rtl:rotate-180">&larr;</span> {{ __('Back to Submissions') }}</a>
    <div class="flex items-center">
        <div class="w-16 h-16 bg-teal-100 dark:bg-teal-900/40 rounded-full flex items-center justify-center me-4">
            <span class="text-teal-700 dark:text-teal-300 font-bold text-xl">{{ strtoupper(substr($user->name ?? $user->email, 0, 2)) }}</span>
        </div>
        <div>
            <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ $user->name ?? __('User') }}</h2>
            <p class="text-gray-500 dark:text-gray-400">{{ $user->email }}</p>
        </div>
    </div>
</div>

<!-- User Stats -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <div class="bg-white dark:bg-gray-900 rounded-lg shadow p-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Today') }}</p>
        <p class="text-3xl font-bold text-gray-800 dark:text-gray-100">{{ $userStats['today'] }}</p>
    </div>
    <div class="bg-white dark:bg-gray-900 rounded-lg shadow p-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Total Submissions') }}</p>
        <p class="text-3xl font-bold text-gray-800 dark:text-gray-100">{{ $userStats['total'] }}</p>
    </div>
    <div class="bg-white dark:bg-gray-900 rounded-lg shadow p-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Cleared') }}</p>
        <p class="text-3xl font-bold text-green-600 dark:text-green-400">{{ $userStats['cleared'] }}</p>
    </div>
    <div class="bg-white dark:bg-gray-900 rounded-lg shadow p-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Rejected') }}</p>
        <p class="text-3xl font-bold {{ $userStats['rejected'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-400 dark:text-gray-500' }}">{{ $userStats['rejected'] }}</p>
    </div>
</div>

<!-- User's Submissions -->
<div class="bg-white dark:bg-gray-900 rounded-lg shadow">
    <div class="p-6 border-b">
        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ __('Submission History') }}</h3>
    </div>
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>{{ __('Invoice') }}</x-ui.th>
            <x-ui.th>{{ __('Type') }}</x-ui.th>
            <x-ui.th>{{ __('State') }}</x-ui.th>
            <x-ui.th>{{ __('Amount') }}</x-ui.th>
            <x-ui.th>{{ __('Date') }}</x-ui.th>
            <x-ui.th>{{ __('Error') }}</x-ui.th>
        </x-slot:head>

        @forelse($submissions as $sub)
        <tr class="hover:bg-gray-50 dark:hover:bg-gray-950">
            <td class="px-6 py-4">
                <p class="text-sm font-medium text-gray-800 dark:text-gray-100">{{ $sub->invoice_number ?? Str::limit($sub->invoice_id, 8) }}</p>
            </td>
            <td class="px-6 py-4">
                <x-ui.badge tone="neutral" :label="ucfirst($sub->submission_type ?? '-')" />
            </td>
            <td class="px-6 py-4">
                <x-ui.badge :state="$sub->state" />
            </td>
            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                @if($sub->invoice_total)
                <span dir="ltr">{{ number_format($sub->invoice_total, 2) }} SAR</span>
                @else
                -
                @endif
            </td>
            <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                {{ \Carbon\Carbon::parse($sub->created_at)->translatedFormat('M d, H:i') }}
            </td>
            <td class="px-6 py-4">
                @if($sub->last_error)
                <span class="text-xs text-red-600 dark:text-red-400" title="{{ $sub->last_error }}">
                    {{ Str::limit($sub->last_error, 30) }}
                </span>
                @else
                <span class="text-gray-400 dark:text-gray-500">-</span>
                @endif
            </td>
        </tr>
        @empty
        <x-ui.empty :columns="6">{{ __('No submissions by this user') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

<div class="mt-4">
    {{ $submissions->appends(request()->query())->links() }}
</div>
@endsection
