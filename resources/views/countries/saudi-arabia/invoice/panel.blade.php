{{--
    What the authority knows about this invoice.

    The QR is drawn, at a size a phone scans from the screen; its base64
    payload, which nobody can scan, is kept for whoever needs it under
    "Technical details". A draft has none of these fields, and saying so
    plainly beats rendering empty rows.

    @param $invoice    \App\Domains\Invoice\Models\Invoice
    @param $qrSvg      string  the QR as SVG markup, '' for none
    @param $shareable  bool    whether its image may be downloaded
    @param $filing     array|null  its latest filing (Presentation::filing)
--}}
<div class="rounded-card bg-surface p-6 border border-line shadow-card">
    <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-muted">{{ __('Compliance') }}</h3>

    @if ($invoice->hash === null)
        <p class="text-sm text-muted">
            {{ __('This invoice is still a draft. It has no counter, no hash and no QR until it is issued.') }}
        </p>
    @else
        <dl class="space-y-3 text-sm">
            @if (! empty($filing))
                {{-- Where its filing is, and why when it did not get through. --}}
                <div data-filing-state="{{ $filing['submission']->state }}">
                    <dt class="text-muted">{{ __('Filing with ZATCA') }}</dt>
                    <dd class="mt-1">
                        <x-ui.badge :tone="$filing['tone']" :label="$filing['label']" />
                        @if ($filing['submission']->last_error && ! in_array($filing['submission']->state, ['cleared', 'reported', 'warning'], true))
                            <p class="mt-1 text-xs text-danger">{{ $filing['submission']->last_error }}</p>
                        @endif
                        @if ($filing['submission']->isResendable() && \App\Domains\Organization\Enums\MemberRole::administers(auth()->user()?->roleIn((string) $invoice->org_id)))
                            <form method="POST" action="{{ route('portal.submissions.retry', $filing['submission']->id) }}" class="mt-2"
                                  data-confirm="{{ __('Send invoice :number to ZATCA again?', ['number' => $invoice->invoice_number]) }}"
                                  data-confirm-detail="{{ __('Every check runs again first. The attempt is kept on the activity record.') }}"
                                  data-confirm-action="{{ __('Send again') }}">
                                @csrf
                                <x-ui.button type="submit" size="sm" variant="secondary">{{ __('Send again') }}</x-ui.button>
                            </form>
                        @endif
                    </dd>
                </div>
            @endif
            <div>
                <dt class="text-muted">{{ __('Counter (ICV)') }}</dt>
                <dd class="font-medium text-fg">{{ $invoice->icv }}</dd>
            </div>
            <div>
                <dt class="text-muted">{{ __('Invoice hash') }}</dt>
                <dd class="break-all font-mono text-xs text-fg">{{ $invoice->hash }}</dd>
            </div>
        </dl>

        @if ($qrSvg !== '')
            <div class="mt-4 flex flex-wrap items-start gap-4">
                <figure>
                    {{-- Unescaped: markup QrCodeRenderer drew from the stored
                         payload. On paper in both themes: a reader needs the
                         contrast. --}}
                    <div class="inline-block rounded-tile bg-paper p-2" data-invoice-qr>{!! $qrSvg !!}</div>
                    <figcaption class="mt-2 text-sm text-fg">
                        <x-caption en="ZATCA QR code" ar="رمز الاستجابة السريعة للفاتورة" />
                    </figcaption>
                </figure>
                {{-- The organization's own QR its invoices carry, apart and
                     labelled: never part of ZATCA's. --}}
                @if (! empty($businessQr))
                    <figure>
                        <div class="inline-block w-32 rounded-tile bg-paper p-2 [&_svg]:h-auto [&_svg]:w-full" data-business-qr>{!! $businessQr['svg'] !!}</div>
                        <figcaption class="mt-2 max-w-32 text-sm text-fg">{{ $businessQr['label'] }}</figcaption>
                    </figure>
                @endif
            </div>
            @if ($shareable)
                <p class="mt-2 flex flex-wrap gap-2">
                    <x-ui.button variant="secondary" :href="route('portal.invoices.qr', [$invoice->id, 'png'])">{{ __('Download QR (PNG)') }}</x-ui.button>
                    <x-ui.button variant="secondary" :href="route('portal.invoices.qr', [$invoice->id, 'svg'])">{{ __('Download QR (SVG)') }}</x-ui.button>
                </p>
            @endif

            <details class="mt-4 text-sm">
                <summary class="min-h-11 cursor-pointer text-muted">{{ __('Technical details') }}</summary>
                <dl class="mt-2">
                    <dt class="text-muted">{{ __('QR payload') }}</dt>
                    <dd class="break-all font-mono text-xs text-muted">{{ $invoice->qr_code }}</dd>
                </dl>
            </details>
        @endif
    @endif
</div>
