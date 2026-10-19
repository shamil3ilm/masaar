{{--
    The invoice's VAT by tax category, as the draft will be saved and the
    document will state it: each category's taxable amount after its share
    of the discount and its charges, and its VAT. A mixed basket - zero-rated
    medicines and standard-rated cosmetics - shows a row for each.

    Filled in by resources/js/invoice-preview.js from the server's own
    arithmetic (PortalInvoicePreviewController); hidden until there is a line
    to show, and absent without script, when the invoice page shows the same
    figures once it is saved.
--}}
<div data-vat-summary hidden class="mb-6 rounded-card bg-surface p-6 border border-line shadow-card"
     data-label-vat="{{ __('VAT') }}" data-label-total="{{ __('Line total') }}">
    <h3 class="mb-1 text-xs font-semibold uppercase tracking-wider text-muted">{{ __('VAT by category') }}</h3>
    <p class="mb-4 text-xs text-muted">{{ __('As the invoice will state it: the document discount is shared across the categories, and each charge is added to its own.') }}</p>

    <x-ui.table collapsible="never">
        <x-slot:head>
            <x-ui.th>{{ __('Category') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Taxable amount') }}</x-ui.th>
            <x-ui.th align="right">{{ __('VAT') }}</x-ui.th>
        </x-slot:head>
    </x-ui.table>

    <dl class="ms-auto mt-4 max-w-xs space-y-1 text-sm">
        @foreach (['subtotal' => __('Lines'), 'discount' => __('Discount'), 'charges' => __('Charges'), 'tax' => __('VAT'), 'total' => __('Total')] as $key => $label)
            <div class="flex justify-between gap-4 {{ $key === 'total' ? 'border-t border-line pt-1 font-semibold text-fg' : 'text-muted' }}">
                <dt>{{ $label }}</dt>
                <dd class="tabular-nums" dir="ltr" data-vat-total="{{ $key }}"></dd>
            </div>
        @endforeach
    </dl>
</div>
