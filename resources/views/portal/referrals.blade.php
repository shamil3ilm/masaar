@extends('layouts.portal')

@section('title', __('Referrals'))

@section('content')
<x-ui.page-header :title="__('Referrals')" :description="__('Recommend us to another company. When it signs up with your link or code and qualifies, you both get a reward.')" />

<div class="max-w-3xl space-y-6">
    @if (! $runs)
        <x-ui.alert tone="info">{{ __('The referral programme is not running for your company at the moment.') }}</x-ui.alert>
    @else
        <x-ui.section :title="__('Your link and code')">
            <div class="space-y-4">
                <x-ui.field :label="__('Referral link')" :hint="__('Share it with the company you recommend; its sign-up form opens with your code.')">
                    <x-ui.input type="text" readonly dir="ltr" value="{{ $link }}" />
                </x-ui.field>
                <x-ui.field :label="__('Referral code')" :hint="__('Or the company types it when it signs up.')">
                    <x-ui.input type="text" readonly dir="ltr" class="font-mono" value="{{ $code }}" />
                </x-ui.field>
            </div>
        </x-ui.section>

        <x-ui.section :title="__('What you both get')">
            <dl class="space-y-2 text-sm">
                <div><dt class="inline text-muted">{{ __('Your company:') }}</dt> <dd class="inline text-fg">{{ $terms->referrer->describe() }}</dd></div>
                <div><dt class="inline text-muted">{{ __('The company you refer:') }}</dt> <dd class="inline text-fg">{{ $terms->referred->describe() }}</dd></div>
                <div><dt class="inline text-muted">{{ __('When:') }}</dt> <dd class="inline text-fg">{{ $terms->qualifyingEvent->label() }}</dd></div>
            </dl>
            <p class="mt-3 text-xs text-subtle">{{ __('A company cannot refer itself, and each company has one referrer. Credit is taken off your next invoices.') }}</p>
        </x-ui.section>
    @endif

    <x-ui.section :title="__('Companies you referred')">
        <div class="overflow-hidden rounded-card border border-line">
            <x-ui.table :primary="[0, 1]">
                <x-slot:head>
                    <x-ui.th>{{ __('Signed up') }}</x-ui.th>
                    <x-ui.th>{{ __('Status') }}</x-ui.th>
                    <x-ui.th>{{ __('Your reward') }}</x-ui.th>
                </x-slot:head>
                @forelse ($referrals as $referral)
                <tr>
                    <td class="px-6 py-4 text-sm text-muted"><x-ui.date :value="$referral->created_at" /></td>
                    <td class="px-6 py-4"><x-ui.badge :tone="$referral->status->tone()" :label="$referral->status->label()" /></td>
                    <td class="px-6 py-4 text-sm text-fg">
                        @php($mine = $referral->rewards->firstWhere('side', 'referrer'))
                        {{ $mine ? $mine->describe().' · '.$mine->status->label() : '-' }}
                    </td>
                </tr>
                @empty
                <x-ui.empty :columns="3">{{ __('No company has signed up with your code yet') }}</x-ui.empty>
                @endforelse
            </x-ui.table>
        </div>
    </x-ui.section>

    <x-ui.section :title="__('Your credit')">
        <div class="overflow-hidden rounded-card border border-line">
            <x-ui.table :primary="[0, 1]">
                <x-slot:head>
                    <x-ui.th>{{ __('Credit') }}</x-ui.th>
                    <x-ui.th>{{ __('Status') }}</x-ui.th>
                    <x-ui.th>{{ __('Use by') }}</x-ui.th>
                </x-slot:head>
                @forelse ($credits as $credit)
                <tr>
                    <td class="px-6 py-4 text-sm text-fg">
                        @if ($credit->kind === 'percent')
                            {{ __(':percent% off the next invoice', ['percent' => (int) $credit->percent]) }}
                        @else
                            <x-ui.amount :value="$credit->amount" :currency="$credit->currency" />
                        @endif
                    </td>
                    <td class="px-6 py-4">
                        <x-ui.badge :tone="$credit->usable() ? 'success' : 'neutral'" :label="$credit->usable() ? __('Available') : ($credit->status === 'redeemed' ? __('Used') : ($credit->status === 'reversed' ? __('Reversed') : __('Expired')))" />
                    </td>
                    <td class="px-6 py-4 text-sm text-muted">
                        @if ($credit->expires_at)<x-ui.date :value="$credit->expires_at" />@else - @endif
                    </td>
                </tr>
                @empty
                <x-ui.empty :columns="3">{{ __('No credit yet') }}</x-ui.empty>
                @endforelse
            </x-ui.table>
        </div>
    </x-ui.section>
</div>
@endsection
