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
        // Whole days: Carbon 3 returns a fraction, which printed as "3.4521 day(s)".
        $daysLeft = (int) \Carbon\Carbon::parse($certificate->valid_to)->diffInDays(now(), false) * -1;
    @endphp
    @if($daysLeft <= 7)
    <x-ui.alert :tone="$daysLeft <= 0 ? 'danger' : 'warning'" class="mb-6">
        @if($daysLeft <= 0)
            {{ __('The certificate has expired. Invoices cannot be submitted until it is renewed.') }}
        @else
            {{ __('Certificate expires in :days day(s). Please renew soon.', ['days' => $daysLeft]) }}
        @endif
    </x-ui.alert>
    @endif
@elseif ($organization->zatca_onboarded)
    <x-ui.alert tone="danger" class="mb-6">{{ __('No active certificate found. Contact support.') }}</x-ui.alert>
@endif

<!-- Stats Cards -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <div class="bg-surface rounded-card border border-line shadow-card p-6">
        <p class="text-sm text-muted">{{ __('Today\'s Invoices') }}</p>
        <p class="text-3xl font-semibold text-fg">{{ $stats['invoices_today'] }}</p>
    </div>
    <div class="bg-surface rounded-card border border-line shadow-card p-6">
        <p class="text-sm text-muted">{{ __('This month') }}</p>
        <p class="text-3xl font-semibold text-fg">{{ $stats['invoices_month'] }}</p>
    </div>
    <div class="bg-surface rounded-card border border-line shadow-card p-6">
        <p class="text-sm text-muted">{{ __('Cleared') }}</p>
        <p class="text-3xl font-semibold text-success">{{ $stats['cleared'] }}</p>
    </div>
    <div class="bg-surface rounded-card border border-line shadow-card p-6">
        <p class="text-sm text-muted">{{ __('Rejected') }}</p>
        <p class="text-3xl font-semibold {{ $stats['rejected'] > 0 ? 'text-danger' : 'text-subtle' }}">{{ $stats['rejected'] }}</p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- User Activity (Last 7 Days) -->
    <div class="bg-surface rounded-card border border-line shadow-card">
        <div class="p-6 border-b">
            <h3 class="text-lg font-semibold text-fg">{{ __('Activity by user (7 days)') }}</h3>
        </div>
        <div class="p-6">
            @if($userActivity->count() > 0)
            <div class="space-y-4">
                @foreach($userActivity as $activity)
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-accent-soft rounded-full flex items-center justify-center me-3">
                            <span class="text-accent font-medium">{{ strtoupper(substr($activity->user_name, 0, 2)) }}</span>
                        </div>
                        <div>
                            <p class="font-medium text-fg">{{ $activity->user_name }}</p>
                            <p class="text-xs text-muted">{{ __(':count submissions', ['count' => $activity->submission_count]) }}</p>
                        </div>
                    </div>
                    @if($activity->user_id)
                    <a href="{{ route('portal.user.activity', ['userId' => $activity->user_id, 'org_id' => request('org_id')]) }}"
                       class="text-accent hover:text-accent-strong text-sm">{{ __('View') }}</a>
                    @endif
                </div>
                @endforeach
            </div>
            @else
            <p class="text-muted text-center py-4">{{ __('No activity in the last 7 days') }}</p>
            @endif
        </div>
    </div>

    <!-- Recent Submissions -->
    <div class="bg-surface rounded-card border border-line shadow-card">
        <div class="p-6 border-b flex justify-between items-center">
            <h3 class="text-lg font-semibold text-fg">{{ __('Recent submissions') }}</h3>
            <a href="{{ route('portal.submissions', ['org_id' => request('org_id')]) }}"
               class="text-accent hover:text-accent-strong text-sm">{{ __('View all') }}</a>
        </div>
        <div class="divide-y">
            @forelse($recentSubmissions as $submission)
            <div class="p-4 flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-fg">{{ $submission->invoice?->invoice_number ?? Str::limit($submission->invoice_id, 8) }}</p>
                    <p class="text-xs text-muted">{{ \Carbon\Carbon::parse($submission->created_at)->diffForHumans() }}</p>
                </div>
                <x-ui.badge :state="$submission->state" />
            </div>
            @empty
            <div class="p-6 text-center text-muted">{{ __('No submissions yet') }}</div>
            @endforelse
        </div>
    </div>
</div>
