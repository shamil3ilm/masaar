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
