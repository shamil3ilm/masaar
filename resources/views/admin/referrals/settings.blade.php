@extends('layouts.admin')

@section('title', 'Referral settings')

@section('content')
<x-ui.page-header title="Referral settings" description="The programme's terms. A change applies to companies that sign up from now on; every referral keeps the terms it signed up under.">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => 'Referrals', 'href' => route('admin.referrals.index')], ['label' => 'Settings']]" />
    </x-slot:breadcrumb>
</x-ui.page-header>

<div class="max-w-3xl space-y-6">
    <x-ui.section title="The programme's terms">
        @admincan('billing.manage')
        <form method="POST" action="{{ route('admin.referrals.settings.update') }}" class="space-y-6">
            @csrf
            @include('admin.referrals.terms-fields')
            <x-ui.button>Save terms</x-ui.button>
        </form>
        @else
        <dl class="space-y-2 text-sm">
            <div><dt class="inline text-muted">Runs:</dt> <dd class="inline">{{ $terms->enabled ? 'Yes' : 'No' }}</dd></div>
            <div><dt class="inline text-muted">The referrer gets:</dt> <dd class="inline">{{ $terms->referrer->describe() }}</dd></div>
            <div><dt class="inline text-muted">The company referred gets:</dt> <dd class="inline">{{ $terms->referred->describe() }}</dd></div>
            <div><dt class="inline text-muted">Rewarded:</dt> <dd class="inline">{{ $terms->qualifyingEvent->label() }}</dd></div>
        </dl>
        <p class="mt-3 text-xs text-subtle">{{ $creditNote }}</p>
        @endadmincan
    </x-ui.section>

    <x-ui.section title="A partner's or reseller's own terms" description="Find the organization by its name or the referral code it hands out.">
        <form method="GET" action="{{ route('admin.referrals.settings') }}" class="flex flex-wrap items-end gap-3">
            <div class="min-w-48 flex-1">
                <x-ui.field label="Organization or code">
                    <x-ui.input type="search" name="q" maxlength="80" value="{{ $search }}" />
                </x-ui.field>
            </div>
            <x-ui.button variant="secondary">Find</x-ui.button>
        </form>
        @if ($search !== '')
            <ul class="mt-4 divide-y divide-line rounded-card border border-line">
                @forelse ($found as $organization)
                    <li class="px-4 py-3 text-sm">
                        <a href="{{ route('admin.referrals.organization', $organization->id) }}" class="text-accent hover:underline">{{ $organization->name }}</a>
                    </li>
                @empty
                    <li class="px-4 py-3 text-sm text-muted">No organization matches.</li>
                @endforelse
            </ul>
        @endif
    </x-ui.section>
</div>
@endsection
