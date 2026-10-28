@extends('layouts.portal')

@section('title', __('Receivables'))

{{--
    What customers owe as at a date: the ageing by currency, and each open
    invoice with what was credited and paid on it by then.

    @param \Illuminate\Support\Collection $rows
    @param array<string, array<string, float>> $ageing
    @param \Carbon\CarbonImmutable $asAt
    @param array<string, string> $filters
--}}

@section('content')
<x-ui.page-header :title="__('Receivables')" :description="__('What customers owe as at :date: invoices less credit notes and payments made by then.', ['date' => $asAt->toDateString()])">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Books'), 'href' => route('portal.books.index')], ['label' => __('Receivables')]]" />
    </x-slot:breadcrumb>
    <x-slot:actions>
        <x-ui.button variant="secondary" :href="route('portal.books.receivables.csv', ['as_at' => $asAt->toDateString(), ...$filters])">{{ __('Download CSV') }}</x-ui.button>
    </x-slot:actions>
</x-ui.page-header>

<x-ui.section :title="__('Filters')" collapse class="mb-6">
    <form method="GET" action="{{ route('portal.books.receivables') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
        <x-ui.field :label="__('As at')">
            <x-ui.date-input name="as_at" value="{{ $asAt->toDateString() }}" />
        </x-ui.field>
        <x-ui.field :label="__('Search')">
            <x-ui.input name="q" value="{{ $filters['q'] }}" placeholder="{{ __('Customer or invoice number') }}" />
        </x-ui.field>
        <x-ui.field :label="__('Status')">
            <x-ui.select name="status">
                @foreach (['open' => __('Still owed'), 'unpaid' => __('Unpaid'), 'part_paid' => __('Part paid'), 'part_credited' => __('Part credited'), 'paid' => __('Paid'), 'credited' => __('Credited'), 'credit' => __('Credit'), 'all' => __('All')] as $value => $label)
                    <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>
        <x-ui.field :label="__('Sort by')">
            <x-ui.select name="sort">
                @foreach (['due' => __('Due date'), 'balance' => __('Owed'), 'customer' => __('Customer'), 'number' => __('Invoice')] as $value => $label)
                    <option value="{{ $value }}" @selected($filters['sort'] === $value)>{{ $label }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>
        <div class="flex items-end"><x-ui.button>{{ __('Apply filters') }}</x-ui.button></div>
    </form>
</x-ui.section>

@foreach ($ageing as $currency => $buckets)
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-6">
        @foreach (\App\Domains\Books\Services\Receivables::BUCKETS as $bucket => $start)
            <x-ui.stat :label="\App\Domains\Books\Services\Receivables::bucketLabel($bucket).' ('.$currency.')'" :value="\App\Domains\Books\Services\Purchases::shown($buckets[$bucket])" />
        @endforeach
        <x-ui.stat :label="__('Total owed').' ('.$currency.')'" :value="\App\Domains\Books\Services\Purchases::shown($buckets['total'])" />
    </div>
@endforeach

<x-ui.card class="overflow-hidden" flush>
    <x-ui.table :primary="[0, 1, 6]">
        <x-slot:head>
            <x-ui.th>{{ __('Invoice') }}</x-ui.th>
            <x-ui.th>{{ __('Customer') }}</x-ui.th>
            <x-ui.th>{{ __('Due') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Total') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Credited') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Paid') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Owed') }}</x-ui.th>
            <x-ui.th>{{ __('Ageing') }}</x-ui.th>
            <x-ui.th>{{ __('Status') }}</x-ui.th>
        </x-slot:head>
        @forelse ($rows as $row)
            <tr class="hover:bg-sunken">
                <td class="px-6 py-3 text-sm"><a href="{{ route('portal.books.invoices.show', $row['invoice']->id) }}" class="text-accent hover:underline" dir="ltr">{{ $row['number'] }}</a></td>
                <td class="px-6 py-3 text-sm text-fg">
                    @if ($row['customer_id'])
                        <a href="{{ route('portal.books.statements.show', $row['customer_id']) }}" class="hover:underline">{{ $row['customer'] }}</a>
                    @else
                        {{ $row['customer'] }}
                    @endif
                </td>
                <td class="px-6 py-3 text-sm"><x-ui.date :value="$row['due_date']" /></td>
                <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$row['total']" :currency="$row['currency']" /></td>
                <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$row['credited']" /></td>
                <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$row['paid']" /></td>
                <td class="px-6 py-3 text-end text-sm font-semibold"><x-ui.amount :value="$row['balance']" :currency="$row['currency']" /></td>
                <td class="px-6 py-3 text-sm text-muted">{{ \App\Domains\Books\Services\Receivables::ageLabel($row) }}</td>
                <td class="px-6 py-3 text-sm">
                    <x-ui.badge :tone="match ($row['status']) { 'paid' => 'success', 'part_paid', 'part_credited' => 'info', 'unpaid' => $row['days_overdue'] > 0 ? 'warning' : 'neutral', default => 'neutral' }"
                                :label="\App\Domains\Books\Services\Receivables::statusLabel($row['status'])" />
                </td>
            </tr>
        @empty
            <x-ui.empty :columns="9">{{ __('Nothing is owed as at this date.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</x-ui.card>
@endsection
