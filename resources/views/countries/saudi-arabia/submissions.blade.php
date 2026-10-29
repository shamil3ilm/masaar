@extends('layouts.portal')

@section('title', __('Submissions - :organization', ['organization' => $organization->name]))

@section('content')
<x-ui.page-header :title="__('Submissions')" :description="__('ZATCA invoice submission history')" />

<!-- Stats -->
<div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-6 gap-4 mb-6">
    <a href="{{ route('portal.submissions') }}"
       class="bg-surface rounded-card border border-line shadow-card p-4 text-center hover:bg-sunken {{ !$state ? 'ring-2 ring-accent' : '' }}">
        <p class="text-2xl font-semibold">{{ array_sum($stateCounts->toArray()) }}</p>
        <p class="text-xs text-muted">{{ __('All') }}</p>
    </a>
    <a href="{{ route('portal.submissions', ['state' => 'cleared']) }}"
       class="bg-surface rounded-card border border-line shadow-card p-4 text-center hover:bg-sunken {{ $state === 'cleared' ? 'ring-2 ring-success' : '' }}">
        <p class="text-2xl font-semibold text-success">{{ $stateCounts['cleared'] ?? 0 }}</p>
        <p class="text-xs text-muted">{{ __('Cleared') }}</p>
    </a>
    <a href="{{ route('portal.submissions', ['state' => 'reported']) }}"
       class="bg-surface rounded-card border border-line shadow-card p-4 text-center hover:bg-sunken {{ $state === 'reported' ? 'ring-2 ring-accent' : '' }}">
        <p class="text-2xl font-semibold text-info">{{ $stateCounts['reported'] ?? 0 }}</p>
        <p class="text-xs text-muted">{{ __('Reported') }}</p>
    </a>
    {{-- Accepted, with advice: counted, and found, apart from the rest. --}}
    <a href="{{ route('portal.submissions', ['state' => 'warning']) }}"
       class="bg-surface rounded-card border border-line shadow-card p-4 text-center hover:bg-sunken {{ $state === 'warning' ? 'ring-2 ring-warning' : '' }}">
        <p class="text-2xl font-semibold text-warning">{{ $stateCounts['warning'] ?? 0 }}</p>
        <p class="text-xs text-muted">{{ __('Accepted with warnings') }}</p>
    </a>
    <a href="{{ route('portal.submissions', ['state' => 'rejected']) }}"
       class="bg-surface rounded-card border border-line shadow-card p-4 text-center hover:bg-sunken {{ $state === 'rejected' ? 'ring-2 ring-danger' : '' }}">
        <p class="text-2xl font-semibold text-danger">{{ $stateCounts['rejected'] ?? 0 }}</p>
        <p class="text-xs text-muted">{{ __('Rejected') }}</p>
    </a>
    <a href="{{ route('portal.submissions', ['state' => 'failed']) }}"
       class="bg-surface rounded-card border border-line shadow-card p-4 text-center hover:bg-sunken {{ $state === 'failed' ? 'ring-2 ring-warning' : '' }}">
        <p class="text-2xl font-semibold text-warning">{{ $stateCounts['failed'] ?? 0 }}</p>
        <p class="text-xs text-muted">{{ __('Failed') }}</p>
    </a>
</div>

<x-ui.list-filters :list="$list" />

<!-- Submissions Table -->
<div class="bg-surface rounded-card border border-line shadow-card overflow-hidden">
    <x-ui.table :primary="[0, 4, 3]">
        <x-slot:head>
            <x-ui.th :list="$list" sort="number">{{ __('Invoice') }}</x-ui.th>
            <x-ui.th>{{ __('User') }}</x-ui.th>
            <x-ui.th>{{ __('Type') }}</x-ui.th>
            <x-ui.th :list="$list" sort="state">{{ __('State') }}</x-ui.th>
            <x-ui.th :list="$list" sort="amount">{{ __('Amount') }}</x-ui.th>
            <x-ui.th :list="$list" sort="newest">{{ __('Date') }}</x-ui.th>
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
                <a href="{{ route('portal.user.activity', ['userId' => $sub->created_by]) }}"
                   class="text-sm text-accent hover:text-accent-strong">
                    {{ $sub->user_name ?? $sub->user_email }}
                </a>
                @else
                <span class="text-sm text-subtle">{{ __('System') }}</span>
                @endif
            </td>
            <td class="px-6 py-4">
                <x-ui.badge tone="neutral" :label="__(ucfirst($sub->submission_type ?? '-'))" />
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
                {{-- As on the invoice page: a refusal in plain words, ZATCA's
                     own text (or the connection's) under Technical details. --}}
                @if($sub->last_error)
                    @if ($sub->state === 'rejected')
                    <p class="text-xs text-danger">{{ \App\Domains\Compliance\Countries\SaudiArabia\Onboarding\ComplianceFindings::rejection(null, (string) $sub->last_error) }}</p>
                    @elseif ($sub->state === 'warning')
                    <p class="text-xs text-warning">{{ \App\Domains\Compliance\Countries\SaudiArabia\Onboarding\ComplianceFindings::advisory((string) $sub->last_error) }}</p>
                    @endif
                    <details class="mt-1 text-xs" data-filing-error>
                        <summary class="min-h-11 cursor-pointer text-muted">{{ __('Technical details') }}</summary>
                        <p class="max-w-xs break-words text-danger" dir="ltr">{{ $sub->last_error }}</p>
                    </details>
                @else
                <span class="text-subtle">-</span>
                @endif
                {{-- Only a failure or a held clearance is sent again (InvoiceSubmission::isResendable), by an administrator. --}}
                @if ($sub->isResendable() && \App\Domains\Organization\Enums\MemberRole::administers(auth()->user()?->roleIn((string) $organization->id)))
                <form method="POST" action="{{ route('portal.submissions.retry', $sub->id) }}" class="mt-1"
                      data-confirm="{{ __('Send invoice :number to ZATCA again?', ['number' => $sub->invoice_number ?? '']) }}"
                      data-confirm-detail="{{ __('Every check runs again first. The attempt is kept on the activity record.') }}"
                      data-confirm-action="{{ __('Send again') }}">
                    @csrf
                    <x-ui.button type="submit" size="sm" variant="secondary">{{ __('Send again') }}</x-ui.button>
                </form>
                @endif
            </td>
        </tr>
        @empty
        <x-ui.empty :columns="7">{{ __('No submissions found') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

{{ $submissions->links() }}
@endsection
