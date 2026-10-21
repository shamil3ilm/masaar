{{--
    The kind of document, above its number. ZATCA's annex titles the two
    documents exactly so. The title is not a proper name, so the Arabic is
    required rather than optional - see resources/views/components/caption.blade.php.

    @param $invoice     \App\Domains\Invoice\Models\Invoice
    @param $simplified  bool
--}}
        {{-- A note is titled as one, as the print view and the PDF title it. --}}
        @php([$titleEn, $titleAr] = match ($invoice->document_type) {
            \App\Domains\Invoice\Enums\DocumentType::CreditNote => ['Credit note', 'إشعار دائن'],
            \App\Domains\Invoice\Enums\DocumentType::DebitNote => ['Debit note', 'إشعار مدين'],
            default => $simplified ? ['Simplified tax invoice', 'فاتورة ضريبية مبسطة'] : ['Tax invoice', 'فاتورة ضريبية'],
        })
        <p class="text-sm font-semibold tracking-wide text-muted uppercase">
            {{ $titleEn }}
            <span class="text-subtle" dir="rtl" lang="ar">{{ $titleAr }}</span>
        </p>
        {{-- A business-to-government transaction (KSA-2 position 9). --}}
        @if ($invoice->is_government)
            <p class="mt-1 text-sm font-medium text-accent">Government (B2G) <span dir="rtl" lang="ar">جهة حكومية</span></p>
        @endif
