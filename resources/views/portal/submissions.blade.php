@extends('layouts.portal')

@section('title', __('Submissions - :organization', ['organization' => $organization->name]))

@section('content')
<div class="mb-8">
    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ __('Submissions') }}</h2>
    <p class="text-gray-500 dark:text-gray-400">{{ __('ZATCA invoice submission history') }}</p>
</div>

<!-- Stats -->
<div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
    <a href="{{ route('portal.submissions', ['org_id' => request('org_id')]) }}"
       class="bg-white dark:bg-gray-900 rounded-lg shadow p-4 text-center hover:bg-gray-50 dark:hover:bg-gray-950 {{ !$state ? 'ring-2 ring-teal-500' : '' }}">
        <p class="text-2xl font-bold">{{ array_sum($stateCounts->toArray()) }}</p>
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('All') }}</p>
    </a>
    <a href="{{ route('portal.submissions', ['org_id' => request('org_id'), 'state' => 'cleared']) }}"
       class="bg-white dark:bg-gray-900 rounded-lg shadow p-4 text-center hover:bg-gray-50 dark:hover:bg-gray-950 {{ $state === 'cleared' ? 'ring-2 ring-green-500' : '' }}">
        <p class="text-2xl font-bold text-green-600 dark:text-green-400">{{ $stateCounts['cleared'] ?? 0 }}</p>
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Cleared') }}</p>
    </a>
    <a href="{{ route('portal.submissions', ['org_id' => request('org_id'), 'state' => 'reported']) }}"
       class="bg-white dark:bg-gray-900 rounded-lg shadow p-4 text-center hover:bg-gray-50 dark:hover:bg-gray-950 {{ $state === 'reported' ? 'ring-2 ring-teal-500' : '' }}">
        <p class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ $stateCounts['reported'] ?? 0 }}</p>
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Reported') }}</p>
    </a>
    <a href="{{ route('portal.submissions', ['org_id' => request('org_id'), 'state' => 'rejected']) }}"
       class="bg-white dark:bg-gray-900 rounded-lg shadow p-4 text-center hover:bg-gray-50 dark:hover:bg-gray-950 {{ $state === 'rejected' ? 'ring-2 ring-red-500' : '' }}">
        <p class="text-2xl font-bold text-red-600 dark:text-red-400">{{ $stateCounts['rejected'] ?? 0 }}</p>
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Rejected') }}</p>
    </a>
    <a href="{{ route('portal.submissions', ['org_id' => request('org_id'), 'state' => 'failed']) }}"
       class="bg-white dark:bg-gray-900 rounded-lg shadow p-4 text-center hover:bg-gray-50 dark:hover:bg-gray-950 {{ $state === 'failed' ? 'ring-2 ring-orange-500' : '' }}">
        <p class="text-2xl font-bold text-orange-600 dark:text-orange-400">{{ $stateCounts['failed'] ?? 0 }}</p>
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Failed') }}</p>
    </a>
</div>

<!-- Filters -->
<div class="bg-white dark:bg-gray-900 rounded-lg shadow p-4 mb-6">
    <form method="GET" class="flex flex-wrap gap-4 items-end">
        <input type="hidden" name="org_id" value="{{ request('org_id') }}">
        <input type="hidden" name="state" value="{{ $state }}">

        <div class="flex-1 min-w-[200px]">
            <x-ui.field :label="__('User')">
            <x-ui.select name="user_id">
                <option value="">{{ __('All Users') }}</option>
                @foreach($users as $user)
                <option value="{{ $user->id }}" {{ $userId == $user->id ? 'selected' : '' }}>
                    {{ $user->name ?? $user->email }}
                </option>
                @endforeach
            </x-ui.select>
            </x-ui.field>
        </div>

        <x-ui.field :label="__('From')">
            <x-ui.input type="date" name="date_from" value="{{ $dateFrom }}" />
        </x-ui.field>

        <x-ui.field :label="__('To')">
            <x-ui.input type="date" name="date_to" value="{{ $dateTo }}" />
        </x-ui.field>

        <div class="flex items-end gap-2">
            <x-ui.button variant="secondary">{{ __('Filter') }}</x-ui.button>
            <x-ui.button variant="quiet" :href="route('portal.submissions', ['org_id' => request('org_id')])">
                {{ __('Clear') }}
            </x-ui.button>
        </div>
    </form>
</div>

<!-- Submissions Table -->
<div class="bg-white dark:bg-gray-900 rounded-lg shadow overflow-hidden">
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>{{ __('Invoice') }}</x-ui.th>
            <x-ui.th>{{ __('User') }}</x-ui.th>
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
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ Str::limit($sub->id, 8) }}</p>
            </td>
            <td class="px-6 py-4">
                @if($sub->user_name || $sub->user_email)
                <a href="{{ route('portal.user.activity', ['userId' => $sub->created_by, 'org_id' => request('org_id')]) }}"
                   class="text-sm text-teal-700 dark:text-teal-300 hover:text-teal-900 dark:hover:text-teal-200">
                    {{ $sub->user_name ?? $sub->user_email }}
                </a>
                @else
                <span class="text-sm text-gray-400 dark:text-gray-500">{{ __('System') }}</span>
                @endif
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
                    {{ Str::limit($sub->last_error, 25) }}
                </span>
                @else
                <span class="text-gray-400 dark:text-gray-500">-</span>
                @endif
            </td>
        </tr>
        @empty
        <x-ui.empty :columns="7">{{ __('No submissions found') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

<div class="mt-4">
    {{ $submissions->appends(request()->query())->links() }}
</div>
@endsection
