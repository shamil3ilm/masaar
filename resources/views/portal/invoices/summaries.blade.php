@extends('layouts.portal')

@section('title', __('Delivery notes and summary invoices'))

{{--
    Deliveries recorded as delivery notes, which are not tax documents, and
    a customer's month gathered into one summary tax invoice
    (SummaryInvoices). The summary opens as a draft to check and issue.

    @param \Illuminate\Contracts\Pagination\LengthAwarePaginator $notes
    @param string|null $show
    @param string $month  the first day of last month, the form's default
    @param \Carbon\CarbonImmutable $deadline  the date last month's summary is issued by
    @param array<string, \Carbon\CarbonImmutable> $issueBy  each delivery not yet invoiced: the date its summary is issued by
--}}

@section('content')
<x-ui.page-header :title="__('Delivery notes and summary invoices')"
                  :description="__('Record what you deliver, then invoice a customer\'s month on one summary tax invoice that lists each delivery and its date.')">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Invoices'), 'href' => route('portal.invoices.index')], ['label' => __('Summary invoices')]]" />
    </x-slot:breadcrumb>
</x-ui.page-header>

<div class="grid gap-6 lg:grid-cols-2">
    <x-ui.card :title="__('Summary invoice for a month')" :description="__('One invoice for every delivery note of the customer in the month not yet invoiced.')">
        <form method="POST" action="{{ route('portal.summaries.store') }}" class="space-y-4" data-summary-form>
            @csrf
            <x-ui.field :label="__('Customer')" error="customer_id">
                <x-ui.select name="customer_id" :source="route('portal.customers.lookup')" required>
                    <option value="">{{ __('Search customers') }}</option>
                </x-ui.select>
            </x-ui.field>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field :label="__('Month')" :hint="__('Any day of the month.')" error="month">
                    <x-ui.date-input name="month" value="{{ old('month', $month) }}" required />
                </x-ui.field>
                <x-ui.field :label="__('Issue date')" error="issue_date" optional>
                    <x-ui.date-input name="issue_date" value="{{ old('issue_date') }}" />
                </x-ui.field>
            </div>
            <p class="text-sm text-muted" data-summary-deadline="{{ $deadline->toDateString() }}">
                {{ __('Issue last month\'s summary invoice by') }} <x-ui.date :value="$deadline" class="font-medium text-fg" />
            </p>
            <x-ui.button>{{ __('Draft the summary invoice') }}</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card :title="__('New delivery note')" :description="__('Not a tax document: no VAT is due on it until the summary invoice.')">
        <form method="POST" action="{{ route('portal.delivery-notes.store') }}" class="space-y-4" data-delivery-note-form>
            @csrf
            <x-ui.field :label="__('Customer')" error="customer_id">
                <x-ui.select name="customer_id" :source="route('portal.customers.lookup')" required>
                    <option value="">{{ __('Search customers') }}</option>
                </x-ui.select>
            </x-ui.field>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field :label="__('Delivered on')" error="delivered_on">
                    <x-ui.date-input name="delivered_on" value="{{ old('delivered_on', now()->toDateString()) }}" required />
                </x-ui.field>
                <x-ui.field :label="__('Number')" :hint="__('Left empty, it is numbered for you.')" error="number" optional>
                    <x-ui.input name="number" value="{{ old('number') }}" maxlength="64" dir="ltr" />
                </x-ui.field>
            </div>
            @foreach (range(0, 2) as $index)
                <div class="grid gap-3 sm:grid-cols-2" data-note-line>
                    <x-ui.field :label="__('Description')" error="lines.{{ $index }}.description" :optional="$index > 0">
                        <x-ui.input name="lines[{{ $index }}][description]" value="{{ old('lines.'.$index.'.description') }}" maxlength="200" />
                    </x-ui.field>
                    <x-ui.field :label="__('Description in Arabic')" error="lines.{{ $index }}.description_ar" optional>
                        <x-ui.input name="lines[{{ $index }}][description_ar]" value="{{ old('lines.'.$index.'.description_ar') }}" maxlength="200" dir="rtl" />
                    </x-ui.field>
                    <x-ui.field :label="__('Quantity')" error="lines.{{ $index }}.quantity" :optional="$index > 0">
                        <x-ui.input type="number" step="0.001" min="0.001" name="lines[{{ $index }}][quantity]" value="{{ old('lines.'.$index.'.quantity', '1') }}" />
                    </x-ui.field>
                    <x-ui.field :label="__('Unit price before VAT')" error="lines.{{ $index }}.unit_price" :optional="$index > 0">
                        <x-ui.input type="number" step="0.01" min="0" name="lines[{{ $index }}][unit_price]" value="{{ old('lines.'.$index.'.unit_price') }}" />
                    </x-ui.field>
                </div>
            @endforeach
            <x-ui.button>{{ __('Record the delivery') }}</x-ui.button>
        </form>
    </x-ui.card>
</div>

<div class="mt-6 mb-3 flex flex-wrap gap-2">
    @foreach (['' => __('All'), 'open' => __('Not invoiced'), 'invoiced' => __('Invoiced')] as $value => $label)
        <x-ui.button size="sm" :href="route('portal.summaries.index', $value === '' ? [] : ['show' => $value])" :variant="($show ?? '') === $value ? 'primary' : 'secondary'">{{ $label }}</x-ui.button>
    @endforeach
</div>

<x-ui.card class="overflow-hidden" flush>
    <x-ui.table :primary="[0, 1, 3]">
        <x-slot:head>
            <x-ui.th>{{ __('Number') }}</x-ui.th>
            <x-ui.th>{{ __('Customer') }}</x-ui.th>
            <x-ui.th>{{ __('Delivered on') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Net amount') }}</x-ui.th>
            <x-ui.th>{{ __('Invoice') }}</x-ui.th>
            <x-ui.th><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
        </x-slot:head>
        @forelse ($notes as $note)
            <tr class="hover:bg-sunken" data-delivery-note>
                <td class="px-6 py-3 text-sm text-fg" dir="ltr">{{ $note->number }}</td>
                <td class="px-6 py-3 text-sm">{{ $note->customer?->name }}</td>
                <td class="px-6 py-3 text-sm"><x-ui.date :value="$note->delivered_on" /></td>
                <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$note->net_amount" /></td>
                <td class="px-6 py-3 text-sm">
                    @if ($note->invoice)
                        <a href="{{ route('portal.invoices.show', $note->invoice_id) }}" class="text-accent hover:underline" dir="ltr">{{ $note->invoice->invoice_number ?? __('Draft') }}</a>
                    @else
                        <x-ui.badge>{{ __('Not invoiced') }}</x-ui.badge>
                        @isset ($issueBy[$note->id])
                            <span class="mt-1 block text-xs text-muted" data-issue-by="{{ $issueBy[$note->id]->toDateString() }}">{{ __('Issue by') }} <x-ui.date :value="$issueBy[$note->id]" /></span>
                        @endisset
                    @endif
                </td>
                <td class="px-6 py-3 text-end text-sm">
                    @unless ($note->invoice_id)
                        <form method="POST" action="{{ route('portal.delivery-notes.remove', $note->id) }}"
                              data-confirm="{{ __('Remove delivery note :number?', ['number' => $note->number]) }}"
                              data-confirm-detail="{{ __('It is removed from the list. A note on an invoice cannot be removed.') }}"
                              data-confirm-action="{{ __('Remove') }}" data-confirm-tone="danger">
                            @csrf
                            <x-ui.button variant="secondary" size="sm">{{ __('Remove') }}</x-ui.button>
                        </form>
                    @endunless
                </td>
            </tr>
        @empty
            <x-ui.empty :columns="6">{{ __('No delivery notes yet.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</x-ui.card>
<div class="mt-4">{{ $notes->links() }}</div>
@endsection
