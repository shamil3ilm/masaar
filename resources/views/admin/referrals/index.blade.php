@extends('layouts.admin')

@section('title', 'Referrals')

@section('content')
<x-ui.page-header title="Referrals" description="Companies that signed up with another's code, the rewards each side was given, and the credit they hold.">
    <x-slot:actions>
        <x-ui.button variant="secondary" :href="route('admin.referrals.settings')">Settings</x-ui.button>
    </x-slot:actions>
</x-ui.page-header>

<div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
    <x-ui.stat label="Referrals" :value="(string) array_sum($summary['statuses'])" />
    <x-ui.stat label="Rewarded" :value="(string) $summary['statuses']['rewarded']" tone="success" />
    <x-ui.stat label="Waiting for an admin" :value="(string) $summary['withheld']" :tone="$summary['withheld'] > 0 ? 'warning' : null" />
    <x-ui.stat label="Free months given" :value="(string) $summary['months']" />
</div>

<x-ui.section title="Rewards and credit" description="Rewards granted by kind, and the programme's credit by currency: granted, used on invoices, and still usable. Reversed credit is left out.">
    <dl class="mb-4 grid grid-cols-1 gap-2 text-sm sm:grid-cols-3">
        @foreach (['percent_off' => 'Percentages off', 'credit' => 'Fixed credits', 'free_months' => 'Free months'] as $kind => $label)
            <div><dt class="inline text-muted">{{ $label }}:</dt> <dd class="inline tabular-nums">{{ $summary['rewards'][$kind] }}</dd></div>
        @endforeach
    </dl>
    <div class="overflow-hidden rounded-card border border-line">
        <x-ui.table :primary="[0, 1, 3]">
            <x-slot:head>
                <x-ui.th>Credit</x-ui.th>
                <x-ui.th align="right">Granted</x-ui.th>
                <x-ui.th align="right">Redeemed</x-ui.th>
                <x-ui.th align="right">Outstanding</x-ui.th>
            </x-slot:head>
            @foreach ($summary['credits']['amounts'] as $currency => $row)
            <tr>
                <td class="px-6 py-4 text-sm text-fg">{{ $currency }}</td>
                <td class="px-6 py-4 text-end text-sm"><x-ui.amount :value="$row['granted']" /></td>
                <td class="px-6 py-4 text-end text-sm"><x-ui.amount :value="$row['redeemed']" /></td>
                <td class="px-6 py-4 text-end text-sm"><x-ui.amount :value="$row['outstanding']" /></td>
            </tr>
            @endforeach
            <tr>
                <td class="px-6 py-4 text-sm text-fg">Percentages off (number)</td>
                <td class="px-6 py-4 text-end text-sm tabular-nums">{{ $summary['credits']['percent']['granted'] }}</td>
                <td class="px-6 py-4 text-end text-sm tabular-nums">{{ $summary['credits']['percent']['redeemed'] }}</td>
                <td class="px-6 py-4 text-end text-sm tabular-nums">{{ $summary['credits']['percent']['outstanding'] }}</td>
            </tr>
        </x-ui.table>
    </div>
</x-ui.section>

<form method="GET" action="{{ route('admin.referrals.index') }}" class="my-6 flex flex-wrap items-end gap-4 rounded-card border border-line bg-surface p-4 shadow-card">
    <div>
        <x-ui.field label="Status">
            <x-ui.select id="status" name="status">
                <option value="">Any status</option>
                @foreach ($statuses as $option)
                    <option value="{{ $option->value }}" @selected($status === $option)>{{ $option->label() }} ({{ $summary['statuses'][$option->value] }})</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>
    </div>
    <x-ui.button variant="secondary">Filter</x-ui.button>
</form>

<div class="overflow-hidden rounded-card border border-line bg-surface shadow-card">
    <x-ui.table :primary="[1, 0, 2]">
        <x-slot:head>
            <x-ui.th>Referrer</x-ui.th>
            <x-ui.th>Company referred</x-ui.th>
            <x-ui.th>Status</x-ui.th>
            <x-ui.th>Rewards</x-ui.th>
            <x-ui.th>Signed up</x-ui.th>
        </x-slot:head>
        @forelse ($referrals as $referral)
        <tr class="hover:bg-sunken">
            <td class="px-6 py-4 text-sm text-muted">
                <a href="{{ route('admin.referrals.organization', $referral->referrer_org_id) }}" class="hover:underline">{{ $referral->referrer?->name }}</a>
            </td>
            <td class="px-6 py-4 text-sm"><a href="{{ route('admin.referrals.show', $referral->id) }}" class="text-accent hover:underline">{{ $referral->organization?->name }}</a></td>
            <td class="px-6 py-4"><x-ui.badge :tone="$referral->status->tone()" :label="$referral->status->label()" /></td>
            <td class="px-6 py-4 text-sm text-muted">{{ $referral->rewards->map(fn ($reward) => $reward->describe())->join(', ') ?: '-' }}</td>
            <td class="px-6 py-4 text-sm text-muted"><x-ui.date :value="$referral->created_at" /></td>
        </tr>
        @empty
        <x-ui.empty :columns="5">No referrals</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

<div class="mt-4">
    {{ $referrals->links() }}
</div>
@endsection
