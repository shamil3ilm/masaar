@extends('layouts.portal')

@section('title', __('Check the documents').' - '.$invoice->invoice_number)

{{--
    What a return, an exchange or a correction will make, before anything is
    made (InvoiceAdjustments::plan). Making them sends the same request
    again, so what is made is what is shown here.

    @param \App\Domains\Invoice\Models\Invoice $invoice
    @param \App\Domains\Invoice\Support\AdjustmentPlan $plan
    @param array<string, string> $hidden  the request, as form fields
--}}

@section('content')
<x-ui.page-header :title="__('Check the documents')" :description="$plan->kind->label()">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Invoices'), 'href' => route('portal.invoices.index')], ['label' => $invoice->invoice_number, 'href' => route('portal.invoices.show', $invoice->id)], ['label' => __('Check the documents')]]" />
    </x-slot:breadcrumb>
</x-ui.page-header>

@if ($plan->reason)
    <x-ui.alert tone="info" class="mb-6">
        <p>{{ __('Reason stated on the note') }}: <span dir="auto">{{ $plan->reason }}</span></p>
    </x-ui.alert>
@endif

@forelse ($plan->documents as $document)
    <x-ui.card class="mb-6" flush data-planned-document="{{ $document['role'] }}"
               :title="match ($document['type']) { 'credit_note' => __('Credit note'), 'debit_note' => __('Debit note'), default => __('Invoice') }"
               :description="$document['issue'] ? __('Issued when you confirm, or sent for approval where notes need one.') : __('Saved as a draft for you to check and issue.')">
        <x-ui.table :primary="[0, 1, 3]">
            <x-slot:head>
                <x-ui.th>{{ __('Item') }}</x-ui.th>
                <x-ui.th align="right">{{ __('Quantity') }}</x-ui.th>
                <x-ui.th align="right">{{ __('Unit price') }}</x-ui.th>
                <x-ui.th align="right">{{ __('VAT rate') }}</x-ui.th>
            </x-slot:head>
            @foreach ($document['lines'] as $line)
                <tr>
                    <td class="px-6 py-3 text-sm text-fg" dir="auto">{{ $line['description'] }}</td>
                    <td class="px-6 py-3 text-end text-sm" dir="ltr">{{ $line['quantity'] }}</td>
                    <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$line['unit_price']" /></td>
                    <td class="px-6 py-3 text-end text-sm" dir="ltr">{{ $line['tax_rate'] }}%</td>
                </tr>
            @endforeach
        </x-ui.table>
        <dl class="grid gap-4 sm:grid-cols-3 border-t border-line px-6 py-4 text-sm">
            <div><dt class="text-muted">{{ __('Subtotal') }}</dt><dd><x-ui.amount :value="$document['totals']['subtotal']" :currency="$invoice->currency" /></dd></div>
            <div><dt class="text-muted">{{ __('VAT') }}</dt><dd><x-ui.amount :value="$document['totals']['tax']" :currency="$invoice->currency" /></dd></div>
            <div><dt class="text-muted">{{ __('Total') }}</dt><dd class="font-semibold" data-planned-total><x-ui.amount :value="$document['totals']['total']" :currency="$invoice->currency" /></dd></div>
        </dl>
    </x-ui.card>
@empty
    <x-ui.alert tone="info" class="mb-6">
        <p>{{ __('No tax document is needed. It is recorded on the invoice, with your note.') }}</p>
    </x-ui.alert>
@endforelse

{{-- Make asks first; Change sends the same request back to the form, so
     nothing entered is lost (PortalInvoiceAdjustmentController::preview). --}}
<form method="POST" action="{{ route('portal.invoices.adjustments.store', $invoice->id) }}" class="flex flex-wrap items-center gap-3">
    @csrf
    @foreach ($hidden as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach
    @php($isolated = "\u{2068}".$invoice->invoice_number."\u{2069}")
    <x-ui.button data-confirm="{{ __('Make these documents for :number?', ['number' => $isolated]) }}"
                 data-confirm-action="{{ __('Make the documents') }}"
                 data-confirm-detail="{{ __('An issued note cannot be undone; it can only be answered by another document.') }}">{{ __('Make the documents') }}</x-ui.button>
    <x-ui.button variant="ghost" name="change" value="1" formaction="{{ route('portal.invoices.adjustments.preview', $invoice->id) }}">{{ __('Change') }}</x-ui.button>
</form>
@endsection
