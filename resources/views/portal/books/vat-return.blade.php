@extends('layouts.portal')

@section('title', __($draft->title))

{{--
    The country's VAT return drafted for the period (VatReturn), section by
    section; every figure of a box with documents links to them.

    @param \App\Domains\Compliance\Engine\DTOs\VatReturnDraft $draft
    @param \Carbon\CarbonImmutable $from
    @param \Carbon\CarbonImmutable $to
    @param array<string, float> $manual
    @param array<string, string> $entries  the figures typed in, key => label
    @param array<string, string> $query
--}}

@section('content')
<x-ui.page-header :title="__($draft->title)" :description="__('From :from to :to, in :currency.', ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'currency' => $draft->currency])">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Books'), 'href' => route('portal.books.index')], ['label' => __($draft->title)]]" />
    </x-slot:breadcrumb>
    <x-slot:actions>
        <x-ui.button variant="secondary" :href="route('portal.books.vat-return.csv', $query)">{{ __('Download CSV') }}</x-ui.button>
        <x-ui.button variant="secondary" :href="route('portal.books.vat-return.pdf', $query)">{{ __('Download PDF') }}</x-ui.button>
    </x-slot:actions>
</x-ui.page-header>

<x-ui.section :title="__('Period and figures you type in')" collapse class="mb-6">
    <form method="GET" action="{{ route('portal.books.vat-return') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.field :label="__('From')">
            <x-ui.date-input name="from" value="{{ $from->toDateString() }}" />
        </x-ui.field>
        <x-ui.field :label="__('To')">
            <x-ui.date-input name="to" value="{{ $to->toDateString() }}" />
        </x-ui.field>
        @foreach ($entries as $key => $label)
            <x-ui.field :label="__($label)" :error="$key" optional>
                <x-ui.input :name="$key" inputmode="decimal" dir="ltr" value="{{ $manual[$key] ?? '' }}" />
            </x-ui.field>
        @endforeach
        <div class="flex items-end">
            <x-ui.button>{{ __('Draft the return') }}</x-ui.button>
        </div>
    </form>
</x-ui.section>

{{-- This period, filed: what has moved since, for the next return's corrections. --}}
@if ($filed)
    <x-ui.alert tone="info" class="mb-4">
        {{ __('This period was filed on :date. The figures below are the return as it stands now; what filing closed counts in the next open period.', ['date' => $filed->filed_on->toDateString()]) }}
    </x-ui.alert>
    @if ($drift !== [])
        <x-ui.alert tone="warning" class="mb-4" :title="__('Changed since it was filed')">
            <ul class="list-disc ps-5">
                @foreach ($drift as $change)
                    <li>{{ __('Box :box (:label): VAT :vat, amount :amount.', ['box' => $change['box'], 'label' => __($change['label']), 'vat' => \App\Domains\Books\Services\Purchases::shown($change['vat']), 'amount' => \App\Domains\Books\Services\Purchases::shown($change['amount'])]) }}</li>
                @endforeach
            </ul>
            <p class="mt-2">{{ __('Correct the difference in the next return, or with the authority as its rules say.') }}</p>
        </x-ui.alert>
    @endif
@elseif ($canFile)
    <x-ui.section :title="__('Mark this period filed')" :description="__('Once filed, its figures are kept as they are now, and later documents dated in it count in the next open period.')" collapse class="mb-6">
        <form method="POST" action="{{ route('portal.books.vat-return.file') }}" class="flex flex-wrap items-end gap-3"
              data-confirm="{{ __('Mark :from to :to filed?', ['from' => $from->toDateString(), 'to' => $to->toDateString()]) }}"
              data-confirm-detail="{{ __('Its figures are kept as filed. Only an owner can reopen it, with a reason.') }}"
              data-confirm-action="{{ __('Mark filed') }}">
            @csrf
            @foreach ($query as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <x-ui.field :label="__('Filed on')" error="filed_on">
                <x-ui.date-input name="filed_on" required value="{{ now()->toDateString() }}" />
            </x-ui.field>
            <x-ui.button>{{ __('Mark filed') }}</x-ui.button>
        </form>
    </x-ui.section>
@endif

@foreach ($draft->problems as $problem)
    <x-ui.alert tone="warning" class="mb-4">{{ $problem }}</x-ui.alert>
@endforeach
@foreach ($draft->links as $label => $url)
    <p class="mb-4 text-sm"><a href="{{ $url }}" class="text-accent hover:underline">{{ $label }}</a></p>
@endforeach

<p class="mb-4 text-sm text-muted">{{ __('This return covers every branch of the organization.') }} {{ __('The Adjustment column shows reductions as negative figures.') }}</p>

@foreach ([...$draft->sections, ...$draft->reconciliation] as $section => $boxes)
    <x-ui.card :title="__($section)" class="mb-6 overflow-hidden" flush>
        <x-ui.table :primary="[1, 4]">
            <x-slot:head>
                <x-ui.th>{{ __('Box') }}</x-ui.th>
                <x-ui.th>{{ __('Description') }}</x-ui.th>
                <x-ui.th align="right">{{ __('Amount (:currency)', ['currency' => $draft->currency]) }}</x-ui.th>
                <x-ui.th align="right">{{ __('Adjustment (:currency)', ['currency' => $draft->currency]) }}</x-ui.th>
                <x-ui.th align="right">{{ __('VAT (:currency)', ['currency' => $draft->currency]) }}</x-ui.th>
            </x-slot:head>
            @foreach ($boxes as $box)
                <tr @class(['font-semibold' => ! $box->traced])>
                    <td class="px-6 py-3 text-sm text-muted" dir="ltr">{{ $box->key }}</td>
                    <td class="px-6 py-3 text-sm text-fg">{{ __($box->label) }}</td>
                    @foreach (['amount', 'adjustment', 'vat'] as $column)
                        <td class="px-6 py-3 text-end text-sm">
                            @if ($box->{$column} === null)
                                <span class="text-subtle">-</span>
                            @elseif ($box->traced)
                                <a href="{{ route('portal.books.vat-return.box', ['box' => $box->key, ...$query]) }}" class="text-accent hover:underline"><x-ui.amount :value="$box->{$column}" /></a>
                            @else
                                <x-ui.amount :value="$box->{$column}" />
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
@endforeach

@foreach ($draft->notes as $note)
    <p class="mb-2 text-sm text-muted">{{ $note }}</p>
@endforeach

<x-ui.card :title="__('Filed periods')" class="mt-6 overflow-hidden" flush>
    <x-ui.table :primary="[0, 2, 3]">
        <x-slot:head>
            <x-ui.th>{{ __('Period') }}</x-ui.th>
            <x-ui.th>{{ __('Filed on') }}</x-ui.th>
            <x-ui.th>{{ __('Status') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Actions') }}</x-ui.th>
        </x-slot:head>
        @forelse ($periods as $period)
            <tr>
                <td class="px-6 py-3 text-sm">
                    <a href="{{ route('portal.books.vat-return', ['from' => $period->starts_on->toDateString(), 'to' => $period->ends_on->toDateString()]) }}" class="text-accent hover:underline" dir="ltr">{{ $period->starts_on->toDateString() }} – {{ $period->ends_on->toDateString() }}</a>
                </td>
                <td class="px-6 py-3 text-sm"><x-ui.date :value="$period->filed_on" /></td>
                <td class="px-6 py-3 text-sm">
                    <x-ui.badge :tone="$period->isFiled() ? 'success' : 'neutral'" :label="$period->isFiled() ? __('Filed') : __('Reopened: :reason', ['reason' => $period->reopen_reason])" />
                </td>
                <td class="px-6 py-3 text-end text-sm">
                    @if ($canReopen && $period->isFiled())
                        <form method="POST" action="{{ route('portal.books.vat-periods.reopen', $period->id) }}" class="inline-flex items-end gap-2"
                              data-confirm-tone="danger" data-confirm-action="{{ __('Reopen period') }}"
                              data-confirm="{{ __('Reopen this filed VAT period?') }}"
                              data-confirm-detail="{{ __('Documents dated in it count in it again. The reopening and its reason are on the activity log.') }}">
                            @csrf
                            <x-ui.input name="reason" maxlength="500" required aria-label="{{ __('Why it is reopened') }}" placeholder="{{ __('Why it is reopened') }}" />
                            <x-ui.button size="sm" variant="secondary">{{ __('Reopen') }}</x-ui.button>
                        </form>
                    @endif
                </td>
            </tr>
        @empty
            <x-ui.empty :columns="4">{{ __('No VAT period is marked filed yet.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</x-ui.card>
@endsection
