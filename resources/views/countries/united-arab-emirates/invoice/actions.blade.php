{{--
    What can be done with an issued UAE invoice: download its e-invoice, and
    print it or download it as a PDF.

    The e-invoice is the tax invoice. A buyer not yet on electronic
    invoicing must also be given a regular one, "e.g. in pdf" (Electronic
    Invoicing Guidelines v1.1, 10.2.2), and the buttons say so
    ($printRequired); to any other buyer the PDF is a copy on request
    (Presentation, PrintedInvoice). A credit note is written on the
    new-invoice form, naming this invoice.

    @param $invoice        \App\Domains\Invoice\Models\Invoice
    @param $document       \App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument|null
    @param $shareable      bool
    @param $printRequired  bool
--}}
            @if ($document)
                <x-ui.button variant="secondary" :href="route('portal.invoices.xml', $invoice->id)">{{ __('Download e-invoice (XML)') }}</x-ui.button>
            @endif
            @if ($shareable ?? false)
                <x-ui.button variant="secondary" :href="route('portal.invoices.print', $invoice->id)">
                    {{ ($printRequired ?? false) ? __('Print tax invoice for the buyer') : __('Print a copy') }}
                </x-ui.button>
                <x-ui.button variant="secondary" :href="route('portal.invoices.pdf', $invoice->id)">PDF</x-ui.button>
            @endif
