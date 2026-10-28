{{--
    What happened to an issued invoice after issue, oldest first: each
    return, exchange, replacement or correction, why, the quantities, and
    the documents made for it with where each stands (InvoiceAdjustments).
    A free replacement has no document; it is this entry alone.

    @param \Illuminate\Support\Collection<int, \App\Domains\Invoice\Models\InvoiceAdjustment> $adjustments
--}}
<x-ui.card :title="__('After issue')" data-adjustments>
    <ol class="space-y-4">
        @foreach ($adjustments as $adjustment)
            <li class="border-s-2 border-line ps-3 text-sm" data-adjustment="{{ $adjustment->kind->value }}">
                <p class="font-medium text-fg">{{ $adjustment->kind->label() }}</p>
                <p class="text-xs text-muted"><x-ui.date :value="$adjustment->created_at" time /></p>
                @if ($adjustment->reason)
                    <p class="mt-1 text-muted" dir="auto">{{ $adjustment->reason }}</p>
                @endif
                @if (! empty($adjustment->details['note']) && $adjustment->details['note'] !== $adjustment->reason)
                    <p class="mt-1 text-muted" dir="auto">{{ $adjustment->details['note'] }}</p>
                @endif
                @foreach ((array) ($adjustment->details['lines'] ?? []) as $line)
                    <p class="mt-1 text-muted"><span dir="auto">{{ $line['description'] ?? '' }}</span> &times; <span dir="ltr">{{ $line['quantity'] ?? '' }}</span></p>
                @endforeach
                @foreach (array_filter([$adjustment->note, $adjustment->newInvoice]) as $document)
                    <p class="mt-1">
                        <a href="{{ route('portal.invoices.show', $document->id) }}" class="text-accent hover:underline">
                            {{ match ($document->document_type?->value) { 'credit_note' => __('Credit note'), 'debit_note' => __('Debit note'), default => __('Invoice') } }}
                            <span dir="ltr">{{ $document->invoice_number ?? __('Draft') }}</span>
                        </a>
                        @if ($document->isEditable() && app(\App\Domains\Platform\Approvals\OrganizationApprovals::class)->pendingFor((string) $document->org_id, (string) $document->id))
                            <x-ui.badge tone="warning" :label="__('Awaiting approval')" />
                        @else
                            @include('portal.partials.invoice-status', ['status' => $document->status])
                        @endif
                    </p>
                @endforeach
            </li>
        @endforeach
    </ol>
</x-ui.card>
