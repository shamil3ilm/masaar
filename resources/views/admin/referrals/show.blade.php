@extends('layouts.admin')

@section('title', 'Referral')

@php($terms = $referral->agreedTerms())

@section('content')
<x-ui.page-header :title="$referral->organization?->name ?? 'Referral'">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => 'Referrals', 'href' => route('admin.referrals.index')], ['label' => $referral->organization?->name ?? 'Referral']]" />
    </x-slot:breadcrumb>
    <x-slot:meta>
        <x-ui.badge :tone="$referral->status->tone()" :label="$referral->status->label()" />
    </x-slot:meta>
    <x-slot:description>
        Referred by <a href="{{ route('admin.referrals.organization', $referral->referrer_org_id) }}" class="text-accent hover:underline">{{ $referral->referrer?->name }}</a>
        with <span class="font-mono" dir="ltr">{{ $referral->code }}</span> &middot; signed up <x-ui.date :value="$referral->created_at" />
        @if ($referral->refusal_reason) &middot; refused: {{ $referral->refusal_reason }} @endif
        @if ($referral->reversal_reason) &middot; reversed: {{ $referral->reversal_reason }} @endif
    </x-slot:description>
</x-ui.page-header>

@if ($referral->review_flagged_at)
    <x-ui.alert tone="warning" class="mb-6">
        {{ __('Needs review since :date: :reason', ['date' => $referral->review_flagged_at->toDateString(), 'reason' => $referral->review_reason]) }}
    </x-ui.alert>
@endif

<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <x-ui.section title="Its terms" description="As they stood when the company signed up.">
            <dl class="space-y-2 text-sm">
                <div><dt class="inline text-muted">The referrer gets:</dt> <dd class="inline">{{ $terms->referrer->describe() }}</dd></div>
                <div><dt class="inline text-muted">The company referred gets:</dt> <dd class="inline">{{ $terms->referred->describe() }}</dd></div>
                <div><dt class="inline text-muted">Rewarded:</dt> <dd class="inline">{{ $terms->qualifyingEvent->label() }}</dd></div>
            </dl>
        </x-ui.section>

        <x-ui.section title="Rewards">
            <ul class="space-y-3">
                @forelse ($referral->rewards as $reward)
                    <li class="rounded-lg border border-line bg-surface p-4 text-sm">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium text-fg">{{ $reward->side === 'referrer' ? 'Referrer' : 'Company referred' }}: {{ $reward->describe() }}</span>
                            <x-ui.badge :tone="$reward->status->tone()" :label="$reward->status->label()" />
                        </div>
                        @if ($reward->withheld_reason)<p class="mt-1 text-muted">{{ $reward->withheld_reason }}</p>@endif
                        @if ($reward->reversal_reason)<p class="mt-1 text-muted">Reversed: {{ $reward->reversal_reason }}</p>@endif
                        @admincan('billing.manage')
                        @if ($reward->status !== \App\Domains\Referral\Enums\RewardStatus::Reversed)
                        <form method="POST" action="{{ route('admin.referrals.adjust', [$referral->id, $reward->id]) }}" class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-[8rem_1fr_auto] sm:items-end">
                            @csrf
                            <x-ui.field :label="match ($reward->kind) { \App\Domains\Referral\Enums\RewardKind::PercentOff => 'Percent', \App\Domains\Referral\Enums\RewardKind::Credit => 'Amount', default => 'Months' }">
                                <x-ui.input type="text" inputmode="decimal" name="value" dir="ltr" required value="{{ $reward->percent ?? $reward->amount ?? $reward->months }}" />
                            </x-ui.field>
                            <x-ui.field label="Why">
                                <x-ui.input type="text" name="reason" maxlength="500" required />
                            </x-ui.field>
                            <x-ui.button variant="secondary">Adjust</x-ui.button>
                        </form>
                        @endif
                        @endadmincan
                    </li>
                @empty
                    <li class="text-sm text-muted">No rewards yet: it has not qualified.</li>
                @endforelse
            </ul>
        </x-ui.section>
    </div>

    <div class="space-y-6">
        @admincan('billing.manage')
        @if (in_array($referral->status, [\App\Domains\Referral\Enums\ReferralStatus::SignedUp, \App\Domains\Referral\Enums\ReferralStatus::Qualified], true))
        <form method="POST" action="{{ route('admin.referrals.approve', $referral->id) }}" class="space-y-3 rounded-card border border-line bg-surface p-4 shadow-card"
              data-confirm-action="Approve"
              data-confirm="Approve this referral?"
              data-confirm-detail="{{ $referral->status === \App\Domains\Referral\Enums\ReferralStatus::SignedUp ? 'It counts as approved; where its terms reward approval, both sides are rewarded now.' : 'Its held-back rewards are granted now, past the limits.' }}">
            @csrf
            <p class="text-sm text-muted">{{ $referral->status === \App\Domains\Referral\Enums\ReferralStatus::SignedUp ? 'Approve it when the company was approved some other way.' : 'Grant the rewards held back, past the limits.' }}</p>
            <x-ui.button class="w-full">Approve</x-ui.button>
        </form>
        @endif

        @if ($referral->status !== \App\Domains\Referral\Enums\ReferralStatus::Reversed)
        <form method="POST" action="{{ route('admin.referrals.reverse', $referral->id) }}" class="space-y-3 rounded-card border border-line bg-surface p-4 shadow-card"
              data-confirm-action="Reverse"
              data-confirm="Reverse this referral and its rewards?"
              data-confirm-detail="Unused credit is taken back and free months come off the plan. A credit already used on an invoice stops it.">
            @csrf
            <x-ui.field label="Why">
                <x-ui.textarea name="reason" rows="2" maxlength="500" required></x-ui.textarea>
            </x-ui.field>
            <x-ui.button variant="secondary" class="w-full">Reverse</x-ui.button>
        </form>
        @endif
        @endadmincan
    </div>
</div>
@endsection
