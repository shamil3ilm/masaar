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

@foreach ($draft->problems as $problem)
    <x-ui.alert tone="warning" class="mb-4">{{ $problem }}</x-ui.alert>
@endforeach

@foreach ($draft->sections as $section => $boxes)
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
@endsection
