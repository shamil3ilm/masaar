{{-- Built for how you sell: the four kinds of business the portal serves. --}}
<section id="business" class="relative scroll-mt-20 py-14 sm:py-16" aria-labelledby="business-title">
    <span class="route-stop" aria-hidden="true"></span>
    <x-ui.section-heading id="business-title" :eyebrow="__('Built for how you sell')"
        :title="__('From a single counter to a wholesale ledger.')"
        :pair="__('From a single counter to a wholesale ledger.', [], $pair)" />

    <div class="mt-8 grid border-t border-line sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            [__('Retail'), __('Invoices at the counter in seconds, from your saved products and their prices.')],
            [__('Wholesale'), __('Tax invoices to registered buyers, and a credit note when an order changes.')],
            [__('Services'), __('Invoice in your customer\'s currency, with their tax details saved once.')],
            [__('Online stores'), __('Connect your store or ERP through the API, and each order\'s invoice is issued for you.')],
        ] as [$kind, $detail])
            <div class="border-b border-line py-6 sm:pe-6">
                <h3 class="text-base font-semibold">{{ $kind }}</h3>
                <p class="mt-2 text-sm leading-relaxed text-muted">{{ $detail }}</p>
            </div>
        @endforeach
    </div>
</section>
