{{--
    The kind of document, above its number: the UAE's six kinds of document
    (PintDocumentCategory).

    @param $invoice  \App\Domains\Invoice\Models\Invoice
--}}
            <p class="text-sm font-semibold tracking-wide text-muted uppercase">
                {{ match ($invoice->jurisdiction_data['document_category'] ?? null) {
                    'tax_credit_note' => __('Tax credit note'),
                    'commercial_invoice' => __('Commercial invoice'),
                    'commercial_credit_note' => __('Commercial credit note'),
                    'self_billed_invoice' => __('Self-billed invoice'),
                    'self_billed_credit_note' => __('Self-billed credit note'),
                    default => __('Tax invoice'),
                } }}
            </p>
