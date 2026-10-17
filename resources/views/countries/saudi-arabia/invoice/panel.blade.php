{{--
    What the authority knows about this invoice.

    Phase 1 deliberately shows no QR image and no actions - those arrive with
    the issue and file phases. A draft has none of these fields, and saying so
    plainly beats rendering empty rows.

    @param $invoice  \App\Domains\Invoice\Models\Invoice
--}}
<div class="rounded-card bg-surface p-6 border border-line shadow-card">
    <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-muted">{{ __('Compliance') }}</h3>

    @if ($invoice->hash === null)
        <p class="text-sm text-muted">
            {{ __('This invoice is still a draft. It has no counter, no hash and no QR until it is issued.') }}
        </p>
    @else
        <dl class="space-y-3 text-sm">
            <div>
                <dt class="text-muted">{{ __('Counter (ICV)') }}</dt>
                <dd class="font-medium text-fg">{{ $invoice->icv }}</dd>
            </div>
            <div>
                <dt class="text-muted">{{ __('Invoice hash') }}</dt>
                <dd class="break-all font-mono text-xs text-fg">{{ $invoice->hash }}</dd>
            </div>
            @if ($invoice->qr_code)
                <div>
                    <dt class="text-muted">{{ __('QR payload') }}</dt>
                    <dd class="break-all font-mono text-xs text-muted">{{ $invoice->qr_code }}</dd>
                </div>
            @endif
        </dl>
    @endif
</div>
