{{--
    What the authority knows about this invoice.

    Phase 1 deliberately shows no QR image and no actions - those arrive with
    the issue and file phases. A draft has none of these fields, and saying so
    plainly beats rendering empty rows.

    @param $invoice  \App\Domains\Invoice\Models\Invoice
--}}
<div class="rounded-lg bg-white dark:bg-gray-900 p-6 shadow">
    <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Compliance</h3>

    @if ($invoice->hash === null)
        <p class="text-sm text-gray-500 dark:text-gray-400">
            This invoice is still a draft. It has no counter, no hash and no QR until it is issued.
        </p>
    @else
        <dl class="space-y-3 text-sm">
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Counter (ICV)</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $invoice->icv }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Invoice hash</dt>
                <dd class="break-all font-mono text-xs text-gray-700 dark:text-gray-200">{{ $invoice->hash }}</dd>
            </div>
            @if ($invoice->qr_code)
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">QR payload</dt>
                    <dd class="break-all font-mono text-xs text-gray-500 dark:text-gray-400">{{ $invoice->qr_code }}</dd>
                </div>
            @endif
        </dl>
    @endif
</div>
