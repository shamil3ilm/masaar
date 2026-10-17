{{--
    What the authority knows about this invoice.

    The QR is drawn, at a size a phone scans from the screen; its base64
    payload, which nobody can scan, is kept for whoever needs it under
    "Technical details". A draft has none of these fields, and saying so
    plainly beats rendering empty rows.

    @param $invoice    \App\Domains\Invoice\Models\Invoice
    @param $qrSvg      string  the QR as SVG markup, '' for none
    @param $shareable  bool    whether its image may be downloaded
--}}
<div class="rounded-lg bg-white dark:bg-gray-900 p-6 shadow">
    <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Compliance') }}</h3>

    @if ($invoice->hash === null)
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('This invoice is still a draft. It has no counter, no hash and no QR until it is issued.') }}
        </p>
    @else
        <dl class="space-y-3 text-sm">
            <div>
                <dt class="text-gray-500 dark:text-gray-400">{{ __('Counter (ICV)') }}</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $invoice->icv }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">{{ __('Invoice hash') }}</dt>
                <dd class="break-all font-mono text-xs text-gray-700 dark:text-gray-200">{{ $invoice->hash }}</dd>
            </div>
        </dl>

        @if ($qrSvg !== '')
            <figure class="mt-4">
                {{-- Unescaped: markup QrCodeRenderer drew from the stored
                     payload. White in both themes: a reader needs the
                     contrast. --}}
                <div class="inline-block bg-white dark:bg-white p-2" data-invoice-qr>{!! $qrSvg !!}</div>
                <figcaption class="mt-2 text-sm text-gray-700 dark:text-gray-200">
                    <x-caption en="ZATCA QR code" ar="رمز الاستجابة السريعة للفاتورة" />
                </figcaption>
            </figure>
            @if ($shareable)
                <p class="mt-2 flex flex-wrap gap-2">
                    <x-ui.button variant="outline" :href="route('portal.invoices.qr', [$invoice->id, 'png'])">{{ __('Download QR (PNG)') }}</x-ui.button>
                    <x-ui.button variant="outline" :href="route('portal.invoices.qr', [$invoice->id, 'svg'])">{{ __('Download QR (SVG)') }}</x-ui.button>
                </p>
            @endif

            <details class="mt-4 text-sm">
                <summary class="cursor-pointer text-gray-500 dark:text-gray-400">{{ __('Technical details') }}</summary>
                <dl class="mt-2">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('QR payload') }}</dt>
                    <dd class="break-all font-mono text-xs text-gray-500 dark:text-gray-400">{{ $invoice->qr_code }}</dd>
                </dl>
            </details>
        @endif
    @endif
</div>
