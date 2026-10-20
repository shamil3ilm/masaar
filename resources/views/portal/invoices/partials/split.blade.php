{{--
    A sale an insurer pays for (InsuranceSplits): who the recipient is, the
    amounts for the account of the insurer and of the patient, the insurer's
    references, its decision, and the documents the sale and the decision
    produced; and the decision form once the invoice is issued.

    @param \App\Domains\Invoice\Models\InsuranceSplit $split
    @param \App\Domains\Invoice\Models\Invoice $invoice  the one this page shows
--}}
@php
    $Split = \App\Domains\Invoice\Models\InsuranceSplit::class;
    $toPatient = $split->recipient === $Split::PATIENT;
    $roles = ['patient' => __('Invoice to the patient'), 'insurer' => __('Invoice to the insurer'), 'rebill' => __('Patient, for care the insurer did not cover')];
    $documents = $split->invoices->sortBy(fn ($document) => array_search($document->split_role, array_keys($roles), true))->values();
    $notes = $split->notes();
    $sale = $split->sale();
    $decidable = $split->status === $Split::BILLED && $sale !== null
        && ! in_array($sale->status, [\App\Domains\Invoice\Enums\InvoiceStatus::Draft, \App\Domains\Invoice\Enums\InvoiceStatus::Rejected], true);
    $decisions = [$Split::NOT_COVERED => __('Not covered'), $Split::PRICE => __('Its agreed price')];
@endphp
<x-ui.section :title="__('Billed with insurer')" class="mb-6" data-split
              :description="$toPatient ? __('The patient is the recipient. The insurer pays its share of this invoice and cannot deduct its VAT.') : __('The insurer is the recipient: it contracted for the care.')">
    <dl class="mb-4 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
        <div class="flex justify-between gap-3"><dt class="text-muted">{{ __('Insurer') }}</dt><dd>{{ $split->insurer_name }}@if ($split->insurer_vat_number) <span dir="ltr" class="text-muted">{{ $split->insurer_vat_number }}</span>@endif</dd></div>
        @if (filled($split->patient_name_ar ?: $split->patient_name))
            <div class="flex justify-between gap-3"><dt class="text-muted">{{ __('Patient') }}</dt><dd>{{ $split->patient_name_ar ?: $split->patient_name }}</dd></div>
        @endif
        @if ($toPatient)
            <div class="flex justify-between gap-3"><dt class="text-muted">{{ __('For the account of the insurer') }}</dt><dd><x-ui.amount :value="$split->insurer_share" :currency="$split->currency" /></dd></div>
            <div class="flex justify-between gap-3"><dt class="text-muted">{{ __('For the account of the patient') }}</dt><dd><x-ui.amount :value="$split->patient_share" :currency="$split->currency" /></dd></div>
        @endif
        @foreach (['claim_reference' => __('Claim or approval number'), 'policy_number' => __('Policy number'), 'member_id' => __('Member number')] as $field => $label)
            @if (filled($split->{$field}))
                <div class="flex justify-between gap-3"><dt class="text-muted">{{ $label }}</dt><dd dir="ltr">{{ $split->{$field} }}</dd></div>
            @endif
        @endforeach
        <div class="flex justify-between gap-3"><dt class="text-muted">{{ __('Insurer\'s decision') }}</dt>
            <dd>
                @if ($split->status === $Split::SETTLED)
                    {{ __('Approved') }} <x-ui.amount :value="$split->insurer_approved" :currency="$split->currency" />
                    @if ($split->decision) <span class="text-muted">({{ $decisions[$split->decision] ?? $split->decision }})</span>@endif
                @else
                    {{ __('Awaited') }}
                @endif
            </dd></div>
        <div class="flex justify-between gap-3"><dt class="text-muted">{{ __('The insurer owes') }}</dt><dd><x-ui.amount :value="$split->insurerOwes()" :currency="$split->currency" /></dd></div>
    </dl>

    <x-ui.table :primary="[0, 1, 3]">
        <x-slot:head>
            <x-ui.th>{{ __('Document') }}</x-ui.th>
            <x-ui.th>{{ __('Number') }}</x-ui.th>
            <x-ui.th>{{ __('Status') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Total') }}</x-ui.th>
        </x-slot:head>
        @foreach ([...$documents->all(), ...$notes->all()] as $document)
            <tr @class(['bg-sunken' => $document->id === $invoice->id])>
                <td class="px-6 py-3 text-sm text-fg">
                    {{ $roles[$document->split_role] ?? ($document->document_type === \App\Domains\Invoice\Enums\DocumentType::CreditNote ? __('Credit note') : __('Debit note')) }}
                    @if ($document->id === $invoice->id)
                        <span class="text-xs text-subtle">({{ __('this one') }})</span>
                    @endif
                </td>
                <td class="px-6 py-3 text-sm">
                    <a href="{{ route('portal.invoices.show', $document->id) }}" class="font-medium underline underline-offset-4" dir="ltr">{{ $document->invoice_number ?? __('Draft') }}</a>
                </td>
                <td class="px-6 py-3 text-sm">@include('portal.partials.invoice-status', ['status' => $document->status])</td>
                <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$document->total" :currency="$document->currency" /></td>
            </tr>
        @endforeach
    </x-ui.table>

    @if ($decidable)
        @canuse('issue_invoices')
        <form method="POST" action="{{ route('portal.invoices.splits.settle', $split->id) }}" class="mt-4 grid gap-4 sm:grid-cols-3 sm:items-end"
              data-confirm-action="{{ __('Record decision') }}"
              data-confirm="{{ __('Record the insurer\'s decision?') }}"
              data-confirm-detail="{{ $toPatient ? __('What the insurer does not pay, the patient owes with its VAT. The invoice stands as issued.') : __('What the insurer does not accept is credited on its invoice now, and care it does not cover is billed to the patient. The decision is recorded once.') }}">
            @csrf
            @if ($toPatient)
                <x-ui.field :label="__('The insurer pays (with VAT)')">
                    <x-ui.input type="number" step="0.01" min="0" name="approved" required value="{{ old('approved', (string) $split->insurer_share) }}" />
                </x-ui.field>
            @else
                <x-ui.field :label="__('The insurer accepts (before VAT)')">
                    <x-ui.input type="number" step="0.01" min="0" name="approved" required value="{{ old('approved', (string) $sale->subtotal) }}" />
                </x-ui.field>
                <x-ui.field :label="__('If it accepts less, because')">
                    <x-ui.select name="decision">
                        @foreach ($decisions as $value => $label)
                            <option value="{{ $value }}" @selected(old('decision') === $value)>{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            @endif
            <div><x-ui.button variant="secondary">{{ __('Record decision') }}</x-ui.button></div>
        </form>
        @endcanuse
    @endif
</x-ui.section>
