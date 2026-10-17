@extends('layouts.portal')

@section('title', __(':name Activity', ['name' => $user->name ?? $user->email]))

@section('content')
<div class="mb-8">
    <a href="{{ route('portal.submissions', ['org_id' => request('org_id')]) }}"
       class="text-accent hover:text-accent-strong text-sm mb-2 inline-block"><span class="inline-block rtl:rotate-180">&larr;</span> {{ __('Back to Submissions') }}</a>
    <div class="flex items-center">
        <div class="w-16 h-16 bg-accent-soft rounded-full flex items-center justify-center me-4">
            <span class="text-accent font-semibold text-xl">{{ strtoupper(substr($user->name ?? $user->email, 0, 2)) }}</span>
        </div>
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-fg">{{ $user->name ?? __('User') }}</h1>
            <p class="mt-1 max-w-3xl text-sm text-muted">{{ $user->email }}</p>
        </div>
    </div>
</div>

<!-- User Stats -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <div class="bg-surface rounded-card border border-line shadow-card p-6">
        <p class="text-sm text-muted">{{ __('Today') }}</p>
        <p class="text-3xl font-semibold text-fg">{{ $userStats['today'] }}</p>
    </div>
    <div class="bg-surface rounded-card border border-line shadow-card p-6">
        <p class="text-sm text-muted">{{ __('Total Submissions') }}</p>
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
        <h3 class="text-lg font-semibold text-fg">{{ __('Submission History') }}</h3>
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
