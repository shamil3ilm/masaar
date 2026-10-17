{{--
    A Saudi organization's dashboard figures (Reports\Dashboard::portal): its
    certificate, its invoices and what ZATCA made of them, and who filed what.

    @param \App\Domains\Organization\Models\Organization $organization
    @param array $stats             invoices_today, invoices_month, cleared, reported, rejected, pending
    @param object|null $certificate  the certificate it signs with, or null before onboarding
    @param \Illuminate\Support\Collection $userActivity       submissions per person, last 7 days
    @param \Illuminate\Support\Collection $recentSubmissions  latest submissions
--}}
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
