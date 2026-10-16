@extends('layouts.portal')

@section('title', __('Dashboard - :organization', ['organization' => $organization->name]))

@php
    // A UAE organization's invoices are Peppol e-invoices: it has no ZATCA
    // certificate, submissions or submission activity to show.
    $uae = $uae ?? false;
@endphp

@section('content')
<div class="mb-8">
    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ __('Dashboard') }}</h2>
    <p class="text-gray-500 dark:text-gray-400">{{ $uae ? __('Your UAE e-invoicing overview') : __('Your ZATCA compliance overview') }}</p>
</div>

@if ($settingUp)
    @php
        $isAdmin = auth()->user()?->roleIn((string) $organization->id) === 'admin';
        $labels = [
            'company' => [__('Complete your company details'), __('Your VAT number and national address, as they go on every invoice.')],
            'approved' => [__('Wait for approval'), __('We review new companies and start their plan, usually within one working day.')],
            'zatca' => [__('Connect to ZATCA'), __('With the one-time code from the Fatoora portal. Your invoices are signed with the certificate it issues.')],
            'numbering' => [__('Check how invoices are numbered'), __('Optional: the defaults work, or set your own prefix and pattern.')],
            'catalogue' => [__('Add your customers and products'), __('Optional: so invoices fill themselves in.')],
            'invoice' => [__('Issue your first invoice'), __('Once it is issued, it is signed and filed with ZATCA.')],
            // A UAE organization's steps (SetupChecklist).
            'uae_company' => [__('Complete your UAE seller details'), __('Your TRN or TIN, address and emirate, legal registration, and the Peppol endpoint your e-invoicing service provider issued.')],
            'uae_invoice' => [__('Issue your first invoice'), __('Once it is issued, it is sent to the buyer as a UAE e-invoice.')],
        ];
    @endphp
    <div class="mb-8 rounded-lg bg-white dark:bg-gray-900 shadow">
        <div class="border-b p-6">
            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ __('Getting set up') }}</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('A few steps before your first invoice. This list goes away once they are done.') }}</p>
        </div>
        <ol class="divide-y divide-gray-200 dark:divide-gray-700">
            @foreach ($setup as $step)
                @php
                    [$title, $detail] = $labels[$step['key']];
                @endphp
                <li class="flex items-start gap-4 p-4">
                    @if ($step['done'])
                        <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-teal-600 text-white" aria-label="{{ __('Done') }}">
                            <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
                        </span>
                    @else
                        <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full border-2 border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-xs text-gray-500 dark:text-gray-400">{{ $loop->iteration }}</span>
                    @endif
                    <div class="flex-1">
                        <p class="font-medium {{ $step['done'] ? 'text-gray-500 dark:text-gray-400 line-through' : 'text-gray-800 dark:text-gray-100' }}">{{ $title }}</p>
                        @unless ($step['done'])
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $detail }}</p>
                        @endunless
                    </div>
                    {{-- Company, ZATCA and numbering are an administrator's;
                         a member is shown what is left, not a link that
                         would refuse them. --}}
                    @if (! $step['done'] && $step['route'] && ($isAdmin || in_array($step['key'], ['catalogue', 'invoice', 'uae_invoice'], true)))
                        <x-ui.button variant="outline" :href="route($step['route'])">{{ __('Start') }}</x-ui.button>
                    @endif
                </li>
            @endforeach
        </ol>
    </div>
@endif

@if ($uae)
    @include('portal.partials.dashboard-uae')
@else
<!-- Certificate Alert -->
@if($certificate)
    @php
        $daysLeft = \Carbon\Carbon::parse($certificate->valid_to)->diffInDays(now(), false) * -1;
    @endphp
    @if($daysLeft <= 7)
    <div class="mb-6 p-4 rounded-lg {{ $daysLeft <= 0 ? 'bg-red-100 dark:bg-red-900/40 border border-red-400' : 'bg-yellow-100 dark:bg-yellow-900/40 border border-yellow-400' }}">
        <div class="flex items-center">
            <svg class="w-5 h-5 me-2 {{ $daysLeft <= 0 ? 'text-red-600 dark:text-red-400' : 'text-yellow-600 dark:text-yellow-400' }}" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
            </svg>
            <span class="font-medium {{ $daysLeft <= 0 ? 'text-red-800 dark:text-red-200' : 'text-yellow-800 dark:text-yellow-200' }}">
                @if($daysLeft <= 0)
                    {{ __('Certificate has EXPIRED! Invoice submissions will fail.') }}
                @else
                    {{ __('Certificate expires in :days day(s). Please renew soon.', ['days' => $daysLeft]) }}
                @endif
            </span>
        </div>
    </div>
    @endif
@elseif ($organization->zatca_onboarded)
    <div class="mb-6 p-4 rounded-lg bg-red-100 dark:bg-red-900/40 border border-red-400">
        <div class="flex items-center">
            <svg class="w-5 h-5 me-2 text-red-600 dark:text-red-400" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
            </svg>
            <span class="font-medium text-red-800 dark:text-red-200">{{ __('No active certificate found. Contact support.') }}</span>
        </div>
    </div>
@endif

<!-- Stats Cards -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <div class="bg-white dark:bg-gray-900 rounded-lg shadow p-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Today\'s Invoices') }}</p>
        <p class="text-3xl font-bold text-gray-800 dark:text-gray-100">{{ $stats['invoices_today'] }}</p>
    </div>
    <div class="bg-white dark:bg-gray-900 rounded-lg shadow p-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('This Month') }}</p>
        <p class="text-3xl font-bold text-gray-800 dark:text-gray-100">{{ $stats['invoices_month'] }}</p>
    </div>
    <div class="bg-white dark:bg-gray-900 rounded-lg shadow p-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Cleared') }}</p>
        <p class="text-3xl font-bold text-green-600 dark:text-green-400">{{ $stats['cleared'] }}</p>
    </div>
    <div class="bg-white dark:bg-gray-900 rounded-lg shadow p-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Rejected') }}</p>
        <p class="text-3xl font-bold {{ $stats['rejected'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-400 dark:text-gray-500' }}">{{ $stats['rejected'] }}</p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- User Activity (Last 7 Days) -->
    <div class="bg-white dark:bg-gray-900 rounded-lg shadow">
        <div class="p-6 border-b">
            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ __('Activity by User (7 days)') }}</h3>
        </div>
        <div class="p-6">
            @if($userActivity->count() > 0)
            <div class="space-y-4">
                @foreach($userActivity as $activity)
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-teal-100 dark:bg-teal-900/40 rounded-full flex items-center justify-center me-3">
                            <span class="text-teal-700 dark:text-teal-300 font-medium">{{ strtoupper(substr($activity->user_name, 0, 2)) }}</span>
                        </div>
                        <div>
                            <p class="font-medium text-gray-800 dark:text-gray-100">{{ $activity->user_name }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __(':count submissions', ['count' => $activity->submission_count]) }}</p>
                        </div>
                    </div>
                    @if($activity->user_id)
                    <a href="{{ route('portal.user.activity', ['userId' => $activity->user_id, 'org_id' => request('org_id')]) }}"
                       class="text-teal-700 dark:text-teal-300 hover:text-teal-900 dark:hover:text-teal-200 text-sm">{{ __('View') }}</a>
                    @endif
                </div>
                @endforeach
            </div>
            @else
            <p class="text-gray-500 dark:text-gray-400 text-center py-4">{{ __('No activity in the last 7 days') }}</p>
            @endif
        </div>
    </div>

    <!-- Recent Submissions -->
    <div class="bg-white dark:bg-gray-900 rounded-lg shadow">
        <div class="p-6 border-b flex justify-between items-center">
            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ __('Recent Submissions') }}</h3>
            <a href="{{ route('portal.submissions', ['org_id' => request('org_id')]) }}"
               class="text-teal-700 dark:text-teal-300 hover:text-teal-900 dark:hover:text-teal-200 text-sm">{{ __('View All') }}</a>
        </div>
        <div class="divide-y">
            @forelse($recentSubmissions as $submission)
            <div class="p-4 flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-800 dark:text-gray-100">{{ Str::limit($submission->invoice_id, 8) }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ \Carbon\Carbon::parse($submission->created_at)->diffForHumans() }}</p>
                </div>
                <x-ui.badge :state="$submission->state" />
            </div>
            @empty
            <div class="p-6 text-center text-gray-500 dark:text-gray-400">{{ __('No submissions yet') }}</div>
            @endforelse
        </div>
    </div>
</div>
@endif
@endsection
