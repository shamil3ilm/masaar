@extends('layouts.admin')

@section('title', 'Referral terms')

@section('content')
<x-ui.page-header :title="$organization->name">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => 'Referrals', 'href' => route('admin.referrals.index')], ['label' => 'Settings', 'href' => route('admin.referrals.settings')], ['label' => $organization->name]]" />
    </x-slot:breadcrumb>
    <x-slot:meta>
        <x-ui.badge :tone="$own ? 'info' : 'neutral'" :label="$own ? 'Its own terms' : 'The programme\'s terms'" />
    </x-slot:meta>
    <x-slot:description>Referral code <span class="font-mono" dir="ltr">{{ $code }}</span> &middot; {{ $made->count() }} referrals made</x-slot:description>
</x-ui.page-header>

<div class="max-w-3xl space-y-6">
    <x-ui.section title="Its terms" description="For a partner or reseller: what it and the companies it refers get, from now on. Referrals already made keep theirs.">
        @admincan('billing.manage')
        <form method="POST" action="{{ route('admin.referrals.organization.update', $organization->id) }}" class="space-y-6">
            @csrf
            @include('admin.referrals.terms-fields', ['terms' => $terms])
            <x-ui.button>Save its terms</x-ui.button>
        </form>
        @if ($own)
            <form method="POST" action="{{ route('admin.referrals.organization.reset', $organization->id) }}" class="mt-4"
                  data-confirm-tone="danger"
                  data-confirm-action="Use the programme's terms"
                  data-confirm="Drop {{ $organization->name }}'s own terms?"
                  data-confirm-detail="Companies it refers from now on get the programme's terms. Referrals already made keep theirs.">
                @csrf
                <x-ui.button variant="secondary">Use the programme's terms</x-ui.button>
            </form>
        @endif
        @else
        <p class="text-sm text-fg">{{ $terms->referrer->describe() }} &middot; {{ $terms->referred->describe() }}</p>
        <p class="mt-3 text-xs text-subtle">{{ $creditNote }}</p>
        @endadmincan
    </x-ui.section>

    <x-ui.section title="Referrals it made">
        <div class="overflow-hidden rounded-card border border-line">
            <x-ui.table :primary="[0, 1, 2]">
                <x-slot:head>
                    <x-ui.th>Company</x-ui.th>
                    <x-ui.th>Status</x-ui.th>
                    <x-ui.th>Signed up</x-ui.th>
                </x-slot:head>
                @forelse ($made as $referral)
                <tr class="hover:bg-sunken">
                    <td class="px-6 py-4 text-sm"><a href="{{ route('admin.referrals.show', $referral->id) }}" class="text-accent hover:underline">{{ $referral->organization?->name }}</a></td>
                    <td class="px-6 py-4"><x-ui.badge :tone="$referral->status->tone()" :label="$referral->status->label()" /></td>
                    <td class="px-6 py-4 text-sm text-muted"><x-ui.date :value="$referral->created_at" /></td>
                </tr>
                @empty
                <x-ui.empty :columns="3">None yet</x-ui.empty>
                @endforelse
            </x-ui.table>
        </div>
    </x-ui.section>
</div>
@endsection
