{{--
    Where this platform files, one tab per country.

    Everything here is the country module's own answer: its name, the system
    it files through and how (its Website port), whether this platform has it
    switched on, which of the platform's features it supports, and the
    standards its documents are built to. A country added to the engine
    appears here without this file changing, and a country switched off in
    the admin console says so rather than disappearing.
--}}
@php
    // What a country's tab lists, when its module supports it.
    $supports = [
        [\App\Domains\Platform\Access\Feature::IssueStandard, __('Tax invoices to registered buyers')],
        [\App\Domains\Platform\Access\Feature::IssueSimplified, __('Simplified invoices for walk-in customers')],
        [\App\Domains\Platform\Access\Feature::CreditNotes, __('Credit notes')],
        [\App\Domains\Platform\Access\Feature::DebitNotes, __('Debit notes')],
        [\App\Domains\Platform\Access\Feature::FileWithAuthority, __('Sent the way the country requires, with its status shown')],
        [\App\Domains\Platform\Access\Feature::ReceiveInvoices, __('Invoices your suppliers send you')],
        [\App\Domains\Platform\Access\Feature::OfflineMode, __('Queued while the authority cannot be reached')],
        [\App\Domains\Platform\Access\Feature::PdfAndEmail, __('A PDF to print or email')],
        [\App\Domains\Platform\Access\Feature::ApiAccess, __('Your ERP, through the API')],
    ];
    $tabs = [];
    foreach ($countries as $country) {
        $tabs[strtolower($country->code())] = __($country->name());
    }
    // The last tab is an invitation: a country that is not here yet.
    $tabs['other'] = __('Other countries');
@endphp

<section id="countries" class="relative scroll-mt-20 py-14 sm:py-16" aria-labelledby="countries-title">
    <span class="route-stop" aria-hidden="true"></span>
    <x-ui.section-heading id="countries-title" :eyebrow="__('Where we work')"
        :title="__('One account. The rules of each country.')"
        :pair="__('One account. The rules of each country.', [], $pair)" />

    @if ($countries !== [])
        <x-ui.tabs id="country" :label="__('Country')" :tabs="$tabs" class="mt-8">
            @foreach ($countries as $country)
                <x-ui.tab-panel id="country" :name="strtolower($country->code())" :selected="$loop->first">
                    <div class="grid gap-4 md:grid-cols-[1fr_1.4fr]">
                        <div @class([
                            'rounded-card p-6',
                            'bg-accent-soft' => $country->enabled(),
                            'bg-highlight-soft' => ! $country->enabled(),
                        ])>
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <h3 class="text-heading font-semibold">{{ __($country->name()) }}</h3>
                                @if (app()->getLocale() !== 'ar')
                                    <span class="text-sm text-subtle" lang="ar" dir="rtl">{{ __($country->name(), [], 'ar') }}</span>
                                @endif
                            </div>
                            <p class="mt-2 text-sm text-muted">{{ __('Invoices go through :system.', ['system' => $country->filingSystem()]) }}</p>
                            <p class="mt-3 text-sm font-medium">{{ $country->website()->offering() }}</p>
                            <p @class(['mt-5 inline-flex items-center gap-2 text-sm font-semibold', 'text-accent' => $country->enabled(), 'text-warning' => ! $country->enabled()])>
                                <span @class(['size-2 rounded-full', 'bg-teal-500' => $country->enabled(), 'bg-sand-200 ring-2 ring-warning/40' => ! $country->enabled()]) aria-hidden="true"></span>
                                {{ $country->enabled() ? __('Available now') : __('Coming soon') }}
                            </p>
                        </div>
                        <ul class="grid gap-x-6 gap-y-1 rounded-card border border-line bg-surface p-6 sm:grid-cols-2">
                            @foreach ($supports as [$feature, $label])
                                @if ($country->features()->supportsFeature($feature))
                                    <li class="flex items-start gap-2.5 py-1.5 text-sm">
                                        <svg class="mt-0.5 size-4 shrink-0 text-accent" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12.5l4.5 4.5L19 7.5" /></svg>
                                        {{ $label }}
                                    </li>
                                @endif
                            @endforeach
                        </ul>
                    </div>
                </x-ui.tab-panel>
            @endforeach
            <x-ui.tab-panel id="country" name="other">
                <div class="flex flex-wrap items-center justify-between gap-4 rounded-card border border-dashed border-line-strong bg-surface p-6">
                    <div class="max-w-xl">
                        <h3 class="text-heading font-semibold">{{ __('Other countries: ask us') }}</h3>
                        <p class="mt-2 text-sm text-muted">{{ __('We can add another country\'s e-invoicing rules to the platform. Tell us where you need to file.') }}</p>
                    </div>
                    <x-ui.button variant="secondary" :href="url('/').'?topic=country#contact'" data-contact-topic="country">{{ __('Ask about a country') }}</x-ui.button>
                </div>
            </x-ui.tab-panel>
        </x-ui.tabs>

        {{-- The published standards the documents are built to, by their
             own names: what the modules follow, not a certificate. --}}
        @if ($publicSite->standards() !== [])
            <p class="mt-6 flex flex-wrap items-baseline gap-x-3 gap-y-1 text-sm text-muted">
                <span class="font-semibold text-fg">{{ __('Standards we follow') }}</span>
                @foreach ($publicSite->standards() as $standard)
                    <span>{{ $standard }}</span>@if (! $loop->last)<span aria-hidden="true">&middot;</span>@endif
                @endforeach
            </p>
        @endif
    @endif
</section>
