{{--
    What can be done with an issued UAE invoice: download its e-invoice.

    No print, PDF or note here. The e-invoice is the document, and the print
    view, the PDF and AdjustmentDraft are built on a hash and QR code a UAE
    invoice does not have (Presentation::assertShareable). A credit note is
    written on the new-invoice form, naming this invoice.

    @param $invoice   \App\Domains\Invoice\Models\Invoice
    @param $document  \App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument|null
--}}
            @if ($document)
                <x-ui.button variant="outline" :href="route('portal.invoices.xml', $invoice->id)">{{ __('Download e-invoice (XML)') }}</x-ui.button>
            @endif
