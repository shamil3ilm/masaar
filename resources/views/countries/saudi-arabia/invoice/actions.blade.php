{{--
    What can be done with an issued Saudi invoice: print it, correct it with a
    credit or debit note, and download its PDF once it may be given to the
    buyer (Presentation::assertShareable).

    @param $invoice    \App\Domains\Invoice\Models\Invoice
    @param $shareable  bool
--}}
            <x-ui.button variant="secondary" :href="route('portal.invoices.print', $invoice->id)">
                {{ __('Print') }}
            </x-ui.button>
            {{-- Not on a rejected invoice: ZATCA never accepted it, so it is
                 reissued rather than adjusted. --}}
            @if (! in_array($invoice->document_type, [\App\Domains\Invoice\Enums\DocumentType::CreditNote, \App\Domains\Invoice\Enums\DocumentType::DebitNote], true)
                && $invoice->status !== \App\Domains\Invoice\Enums\InvoiceStatus::Rejected
                && ! \App\Domains\Organization\Enums\MemberRole::readOnly(auth()->user()?->roleIn((string) request()->attributes->get(\App\Domains\Organization\Http\Middleware\PortalTenant::ORG_ID))))
                <x-ui.button variant="secondary" :href="route('portal.invoices.adjust', [$invoice->id, 'credit_note'])">{{ __('Credit note') }}</x-ui.button>
                <x-ui.button variant="secondary" :href="route('portal.invoices.adjust', [$invoice->id, 'debit_note'])">{{ __('Debit note') }}</x-ui.button>
            @endif
            @if ($shareable)
                <x-ui.button variant="secondary" :href="route('portal.invoices.pdf', $invoice->id)">
                    PDF
                </x-ui.button>
            @endif
