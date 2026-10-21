@extends('layouts.portal')

@section('title', __('Activity of :name', ['name' => $user->name ?? $user->email]))

@section('content')
<x-ui.page-header :title="$user->name ?? __('User')" :description="$user->email" class="mb-8">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Submissions'), 'href' => route('portal.submissions', ['org_id' => request('org_id')])], ['label' => $user->name ?? $user->email]]" />
    </x-slot:breadcrumb>
</x-ui.page-header>

<!-- User Stats -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <div class="bg-surface rounded-card border border-line shadow-card p-6">
        <p class="text-sm text-muted">{{ __('Today') }}</p>
        <p class="text-3xl font-semibold text-fg">{{ $userStats['today'] }}</p>
    </div>
    <div class="bg-surface rounded-card border border-line shadow-card p-6">
        <p class="text-sm text-muted">{{ __('Total submissions') }}</p>
        <p class="text-3xl font-semibold text-fg">{{ $userStats['total'] }}</p>
    </div>
    <div class="bg-surface rounded-card border border-line shadow-card p-6">
        <p class="text-sm text-muted">{{ __('Cleared') }}</p>
        <p class="text-3xl font-semibold text-success">{{ $userStats['cleared'] }}</p>
    </div>
    <div class="bg-surface rounded-card border border-line shadow-card p-6">
        <p class="text-sm text-muted">{{ __('Rejected') }}</p>
        <p class="text-3xl font-semibold {{ $userStats['rejected'] > 0 ? 'text-danger' : 'text-subtle' }}">{{ $userStats['rejected'] }}</p>
    </div>
</div>

<!-- User's Submissions -->
<div class="bg-surface rounded-card border border-line shadow-card">
    <div class="p-6 border-b">
        <h3 class="text-lg font-semibold text-fg">{{ __('Submission history') }}</h3>
    </div>
    <x-ui.table :primary="[0, 3, 2]">
        <x-slot:head>
            <x-ui.th>{{ __('Invoice') }}</x-ui.th>
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
            </td>
            <td class="px-6 py-4">
                <x-ui.badge tone="neutral" :label="ucfirst($sub->submission_type ?? '-')" />
            </td>
            <td class="px-6 py-4">
                <x-ui.badge :state="$sub->state" />
            </td>
            <td class="px-6 py-4 text-sm text-muted">
                @if($sub->invoice_total)
                <x-ui.amount :value="$sub->invoice_total" :currency="$sub->invoice_currency" />
                @else
                -
                @endif
            </td>
            <td class="px-6 py-4 text-sm text-muted">
                <x-ui.date :value="$sub->created_at" time :zone="\App\Support\CountryClock::timeZone($organization->country)" />
            </td>
            <td class="px-6 py-4">
                @if($sub->last_error)
                <span class="text-xs text-danger" title="{{ $sub->last_error }}">
                    {{ Str::limit($sub->last_error, 30) }}
                </span>
                @else
                <span class="text-subtle">-</span>
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
