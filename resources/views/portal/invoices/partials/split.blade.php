{{--
    The split sale this invoice bills a share of (InsuranceSplits): both
    shares with their link, the patient's invoice for anything the insurer
    declined, and the notes against them; what the sale came to and what
    its documents now bill; issuing both at once, and the insurer's decision.

    @param \App\Domains\Invoice\Models\InsuranceSplit $split
    @param \App\Domains\Invoice\Models\Invoice $invoice  the one this page shows
--}}
@php
    $roles = ['patient' => __('Patient\'s share'), 'insurer' => __('Insurer\'s share'), 'rebill' => __('Patient, for what the insurer declined')];
    $documents = $split->invoices->sortBy(fn ($share) => array_search($share->split_role, array_keys($roles), true))->values();
    $notes = $split->notes();
    $insurer = $split->share('insurer');
    $drafts = $documents->contains(fn ($share) => $share->status === \App\Domains\Invoice\Enums\InvoiceStatus::Draft);
    $decidable = $split->status === \App\Domains\Invoice\Models\InsuranceSplit::BILLED && ! $drafts
        && $insurer !== null && $insurer->status !== \App\Domains\Invoice\Enums\InvoiceStatus::Rejected;
    $divided = match ($split->method) {
        'percent' => __('The patient pays :percent% of each line.', ['percent' => rtrim(rtrim((string) $split->patient_percent, '0'), '.')]),
        'amount' => __('The patient pays a fixed amount, spread over the lines.'),
        default => __('Each line is paid by the patient or by the insurer.'),
    };
@endphp
<x-ui.section :title="__('Split sale')" :description="$divided" class="mb-6" data-split>
    <dl class="mb-4 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
        <div class="flex justify-between gap-3"><dt class="text-muted">{{ __('Sale total') }}</dt><dd class="tabular-nums">@include('portal.partials.money', ['amount' => $split->sale_total, 'currency' => $split->currency])</dd></div>
        @if ($split->method === 'amount')
            <div class="flex justify-between gap-3"><dt class="text-muted">{{ __('Patient\'s amount') }}</dt><dd><x-ui.amount :value="$split->patient_amount" :currency="$split->currency" /></dd></div>
        @endif
        <div class="flex justify-between gap-3"><dt class="text-muted">{{ __('Billed, after notes') }}</dt><dd class="tabular-nums">@include('portal.partials.money', ['amount' => $split->billed(), 'currency' => $split->currency])</dd></div>
        @foreach (['claim_reference' => __('Claim or approval number'), 'policy_number' => __('Policy number'), 'member_id' => __('Member number')] as $field => $label)
            @if (filled($split->{$field}))
                <div class="flex justify-between gap-3"><dt class="text-muted">{{ $label }}</dt><dd dir="ltr">{{ $split->{$field} }}</dd></div>
            @endif
        @endforeach
        <div class="flex justify-between gap-3"><dt class="text-muted">{{ __('Insurer\'s decision') }}</dt>
            <dd>
                @if ($split->status === \App\Domains\Invoice\Models\InsuranceSplit::SETTLED)
                    {{ __('Approved') }} <x-ui.amount :value="$split->insurer_approved" :currency="$split->currency" />
                @else
                    {{ __('Awaited') }}
                @endif
            </dd></div>
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
                <td class="px-6 py-3 text-end text-sm tabular-nums">@include('portal.partials.money', ['amount' => $document->total, 'currency' => $document->currency])</td>
            </tr>
        @endforeach
    </x-ui.table>

    @if ($drafts)
        @canuse('issue_invoices')
        <form method="POST" action="{{ route('portal.invoices.splits.issue', $split->id) }}" class="mt-4"
              data-confirm-action="{{ __('Issue both') }}"
              data-confirm="{{ __('Issue the patient\'s invoice and the insurer\'s?') }}"
              data-confirm-detail="{{ __('Each takes its number and its place in the chain, the patient\'s first. An issued invoice cannot be changed.') }}"
              data-confirm-type="{{ __('Issue') }}">
            @csrf
            <x-ui.button>{{ __('Issue both') }}</x-ui.button>
        </form>
        @endcanuse
    @endif

    @if ($decidable)
        @canuse('issue_invoices')
        <form method="POST" action="{{ route('portal.invoices.splits.settle', $split->id) }}" class="mt-4 grid gap-4 sm:grid-cols-3 sm:items-end"
              data-confirm-action="{{ __('Record decision') }}"
              data-confirm="{{ __('Record the insurer\'s decision?') }}"
              data-confirm-detail="{{ __('What it declined is credited on its invoice and billed to the patient on a new invoice, both issued now. The decision is recorded once.') }}">
            @csrf
            <x-ui.field :label="__('The insurer approved (with VAT)')">
                <x-ui.input type="number" step="0.01" min="0" name="approved" required value="{{ old('approved', (string) $insurer->total) }}" />
            </x-ui.field>
            <x-ui.field :label="__('Reason, if it declined some')">
                <x-ui.select name="adjustment_reason">
                    <option value="">{{ __('Cancelled wholly or partly (the usual one)') }}</option>
                    @foreach (\App\Domains\Invoice\Support\AdjustmentReason::values() as $reason)
                        <option value="{{ $reason }}" @selected(old('adjustment_reason') === $reason)>{{ $reason }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <div><x-ui.button variant="secondary">{{ __('Record decision') }}</x-ui.button></div>
        </form>
        @endcanuse
    @endif
</x-ui.section>
