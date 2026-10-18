@extends('layouts.portal')

@section('title', __('Submissions - :organization', ['organization' => $organization->name]))

@section('content')
<div class="mb-8">
    <h1 class="text-2xl font-semibold tracking-tight text-fg">{{ __('Submissions') }}</h1>
    <p class="mt-1 max-w-3xl text-sm text-muted">{{ __('ZATCA invoice submission history') }}</p>
</div>

<!-- Stats -->
<div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
    <a href="{{ route('portal.submissions', ['org_id' => request('org_id')]) }}"
       class="bg-surface rounded-card border border-line shadow-card p-4 text-center hover:bg-sunken {{ !$state ? 'ring-2 ring-accent' : '' }}">
        <p class="text-2xl font-semibold">{{ array_sum($stateCounts->toArray()) }}</p>
        <p class="text-xs text-muted">{{ __('All') }}</p>
    </a>
    <a href="{{ route('portal.submissions', ['org_id' => request('org_id'), 'state' => 'cleared']) }}"
       class="bg-surface rounded-card border border-line shadow-card p-4 text-center hover:bg-sunken {{ $state === 'cleared' ? 'ring-2 ring-success' : '' }}">
        <p class="text-2xl font-semibold text-success">{{ $stateCounts['cleared'] ?? 0 }}</p>
        <p class="text-xs text-muted">{{ __('Cleared') }}</p>
    </a>
    <a href="{{ route('portal.submissions', ['org_id' => request('org_id'), 'state' => 'reported']) }}"
       class="bg-surface rounded-card border border-line shadow-card p-4 text-center hover:bg-sunken {{ $state === 'reported' ? 'ring-2 ring-accent' : '' }}">
        <p class="text-2xl font-semibold text-info">{{ $stateCounts['reported'] ?? 0 }}</p>
        <p class="text-xs text-muted">{{ __('Reported') }}</p>
    </a>
    <a href="{{ route('portal.submissions', ['org_id' => request('org_id'), 'state' => 'rejected']) }}"
       class="bg-surface rounded-card border border-line shadow-card p-4 text-center hover:bg-sunken {{ $state === 'rejected' ? 'ring-2 ring-danger' : '' }}">
        <p class="text-2xl font-semibold text-danger">{{ $stateCounts['rejected'] ?? 0 }}</p>
        <p class="text-xs text-muted">{{ __('Rejected') }}</p>
    </a>
    <a href="{{ route('portal.submissions', ['org_id' => request('org_id'), 'state' => 'failed']) }}"
       class="bg-surface rounded-card border border-line shadow-card p-4 text-center hover:bg-sunken {{ $state === 'failed' ? 'ring-2 ring-warning' : '' }}">
        <p class="text-2xl font-semibold text-warning">{{ $stateCounts['failed'] ?? 0 }}</p>
        <p class="text-xs text-muted">{{ __('Failed') }}</p>
    </a>
</div>

<!-- Filters -->
<div class="bg-surface rounded-card border border-line shadow-card p-4 mb-6">
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
            <x-ui.button variant="ghost" :href="route('portal.submissions', ['org_id' => request('org_id')])">
                {{ __('Clear') }}
            </x-ui.button>
        </div>
    </form>
</div>

<!-- Submissions Table -->
<div class="bg-surface rounded-card border border-line shadow-card overflow-hidden">
    <x-ui.table :primary="[0, 4, 3]">
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
        <tr class="hover:bg-sunken">
            <td class="px-6 py-4">
                <p class="text-sm font-medium text-fg">{{ $sub->invoice_number ?? Str::limit($sub->invoice_id, 8) }}</p>
                <p class="text-xs text-muted">{{ Str::limit($sub->id, 8) }}</p>
            </td>
            <td class="px-6 py-4">
                @if($sub->user_name || $sub->user_email)
                <a href="{{ route('portal.user.activity', ['userId' => $sub->created_by, 'org_id' => request('org_id')]) }}"
                   class="text-sm text-accent hover:text-accent-strong">
                    {{ $sub->user_name ?? $sub->user_email }}
                </a>
                @else
                <span class="text-sm text-subtle">{{ __('System') }}</span>
                @endif
            </td>
            <td class="px-6 py-4">
                <x-ui.badge tone="neutral" :label="ucfirst($sub->submission_type ?? '-')" />
            </td>
            <td class="px-6 py-4">
                <x-ui.badge :state="$sub->state" />
            </td>
            <td class="px-6 py-4 text-sm text-muted">
                @if($sub->invoice_total)
                <span dir="ltr">{{ number_format($sub->invoice_total, 2) }} SAR</span>
                @else
                -
                @endif
            </td>
            <td class="px-6 py-4 text-sm text-muted">
                {{ \Carbon\Carbon::parse($sub->created_at)->translatedFormat('M d, H:i') }}
            </td>
            <td class="px-6 py-4">
                @if($sub->last_error)
                <span class="text-xs text-danger" title="{{ $sub->last_error }}">
                    {{ Str::limit($sub->last_error, 25) }}
                </span>
                @else
                <span class="text-subtle">-</span>
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
