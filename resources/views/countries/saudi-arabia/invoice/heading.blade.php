{{--
    The kind of document, above its number. ZATCA's annex titles the two
    documents exactly so. The title is not a proper name, so the Arabic is
    required rather than optional - see resources/views/components/caption.blade.php.

    @param $invoice     \App\Domains\Invoice\Models\Invoice
    @param $simplified  bool
--}}
        <p class="text-sm font-semibold tracking-wide text-muted uppercase">
            {{ $simplified ? 'Simplified tax invoice' : 'Tax invoice' }}
            <span class="text-subtle" dir="rtl" lang="ar">{{ $simplified ? 'فاتورة ضريبية مبسطة' : 'فاتورة ضريبية' }}</span>
        </p>
        {{-- A business-to-government transaction (KSA-2 position 9). --}}
        @if ($invoice->is_government)
            <p class="mt-1 text-sm font-medium text-accent">Government (B2G) <span dir="rtl" lang="ar">جهة حكومية</span></p>
        @endif
