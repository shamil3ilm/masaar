{{--
    A UAE organization's dashboard figures (Reports\Dashboard::portal): its
    invoices, and where their e-invoices are (UaeDocuments::counts): delivery
    to the buyer and the report to the authority are counted apart, since
    the provider confirms each on its own. Not sent is a document the queue
    gave up on.

    @param \App\Domains\Organization\Models\Organization $organization
    @param array $stats             invoices_today, invoices_month
    @param array $einvoices         awaiting, delivered, rejected, failed, received, reported, report_failed, not_exchanged
    @param \Illuminate\Support\Collection $recentDocuments  latest sent PeppolDocuments
    @param \Carbon\CarbonImmutable|null $einvoicingStart  EinvoicingStart::date
    @param bool $inScope  whether e-invoicing has started
    @param array|null $startReminder  EinvoicingStartReminders::due
--}}
{{-- Its stage: before e-invoicing (its invoices are kept, never sent) or via
     its provider from its start date (UAE Electronic Invoicing Guidelines v1.1, 6.1). --}}
<div class="mb-6 flex flex-wrap items-center gap-2 text-sm" data-einvoicing-stage>
    @if ($inScope)
        <x-ui.badge tone="success" :label="__('E-invoicing via provider from :date', ['date' => $einvoicingStart->toDateString()])" />
    @else
        <x-ui.badge tone="neutral" :label="__('Before e-invoicing')" />
        {{-- Said once: while the setup list shows, its step says the same. --}}
        @unless ($settingUp ?? false)
            <span class="text-muted">
                @if ($einvoicingStart)
                    {{ __('From :date, every invoice you issue is sent through your service provider.', ['date' => $einvoicingStart->toDateString()]) }}
                @else
                    {{ __('Your invoices are tax invoices kept here and not sent. Set your e-invoicing start date on your company page.') }}
                @endif
            </span>
        @endunless
    @endif
</div>

@if ($startReminder)
    <x-ui.alert tone="warning" class="mb-6" data-einvoicing-reminder>
        {{ __('Your e-invoicing starts on :date, in :days days, and no service provider is recorded as appointed. Appoint one through EmaraTax and record it on your company page: from that date invoices cannot be issued without it.', ['date' => $startReminder['date']->toDateString(), 'days' => $startReminder['days']]) }}
        {{-- The company page is an administrator's: no link that would refuse anyone else. --}}
        @if (\App\Domains\Organization\Enums\MemberRole::administers(auth()->user()?->roleIn((string) $organization->id)))
            <a href="{{ route('portal.company.edit') }}" class="text-accent hover:text-accent-strong">{{ __('Record your service provider') }}</a>
        @endif
    </x-ui.alert>
@endif

<div class="grid grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <div class="bg-surface rounded-card border border-line shadow-card p-6">
        <p class="text-sm text-muted">{{ __('Today\'s Invoices') }}</p>
        <p class="text-3xl font-semibold text-fg">{{ $stats['invoices_today'] }}</p>
    </div>
    <div class="bg-surface rounded-card border border-line shadow-card p-6">
        <p class="text-sm text-muted">{{ __('This month') }}</p>
        <p class="text-3xl font-semibold text-fg">{{ $stats['invoices_month'] }}</p>
    </div>
    {{-- Where the e-invoices stand: a tile for each state that has any, each
         saying what it means. A state with none is left out - a new owner
         was shown eleven zeros with no word of what they were. --}}
    @php
        $states = array_filter([
        'delivered' => [__('Delivered'), __('Received by the buyer.'), 'text-success'],
        'awaiting' => [__('Awaiting delivery'), __('Sent; the buyer\'s provider has not confirmed it yet.'), 'text-fg'],
        'rejected' => [__('Rejected'), __('Refused by the buyer\'s provider. Open it to see why.'), 'text-danger'],
        'failed' => [__('Not sent'), __('Could not be sent after several tries. Open it to send it again.'), 'text-warning'],
        'reported' => [__('Reported to the authority'), __('Your provider reported it to the tax authority.'), 'text-success'],
        'report_failed' => [__('Report to the authority failed'), __('Your provider could not report it. Open it to see why.'), 'text-danger'],
        'not_exchanged' => [__('Reported only, not exchanged'), __('Reported to the authority, with no buyer to deliver it to.'), 'text-fg'],
        'not_transmitted' => [__('Issued, not transmitted: before e-invoicing start'), __('Issued before your e-invoicing started, so kept here and never sent.'), 'text-fg'],
        ], fn (array $state, string $key): bool => ($einvoices[$key] ?? 0) > 0, ARRAY_FILTER_USE_BOTH);
    @endphp
    @foreach ($states as $key => [$label, $hint, $tone])
        <div class="bg-surface rounded-card border border-line shadow-card p-6" data-einvoice-state="{{ $key }}">
            <p class="text-sm text-muted">{{ $label }}</p>
            <p class="text-3xl font-semibold {{ $tone }}">{{ $einvoices[$key] }}</p>
            <p class="mt-1 text-xs text-muted">{{ $hint }}</p>
        </div>
    @endforeach
    {{-- The received screens are a UAE organization's. --}}
    @if ($einvoices['received'] > 0 || \App\Domains\Compliance\Countries\UnitedArabEmirates\Support\UaePortal::offers($organization))
        <div class="bg-surface rounded-card border border-line shadow-card p-6">
            <p class="text-sm text-muted">{{ __('Received invoices') }}</p>
            <p class="text-3xl font-semibold text-fg">{{ $einvoices['received'] }}</p>
            @if (\App\Domains\Compliance\Countries\UnitedArabEmirates\Support\UaePortal::offers($organization))
                <a href="{{ route('portal.received.index') }}" class="text-accent hover:text-accent-strong text-sm">{{ __('View') }}</a>
            @endif
        </div>
    @endif
</div>

@if ($states === [])
    <p class="mb-8 text-sm text-muted" data-einvoice-states-empty>{{ __('Where each e-invoice stands - delivered to the buyer, reported to the authority - shows here once you send your first one.') }}</p>
@endif

<div class="bg-surface rounded-card border border-line shadow-card">
    <div class="p-6 border-b flex justify-between items-center">
        <h3 class="text-lg font-semibold text-fg">{{ __('Recent e-invoices') }}</h3>
        <a href="{{ route('portal.invoices.index') }}"
           class="text-accent hover:text-accent-strong text-sm">{{ __('View all') }}</a>
    </div>
    <div class="divide-y">
        @forelse ($recentDocuments as $document)
        <div class="p-4 flex items-center justify-between">
            <div>
                @if ($document->invoice_id)
                    <a href="{{ route('portal.invoices.show', $document->invoice_id) }}" class="text-sm font-medium text-accent hover:underline">{{ Str::limit($document->invoice_id, 8) }}</a>
                @endif
                <p class="text-xs text-muted">{{ \Carbon\Carbon::parse($document->created_at)->diffForHumans() }}</p>
            </div>
            <div class="flex flex-wrap justify-end gap-2">
                @include('networks.peppol.status', ['status' => $document->status])
                @if ($document->reporting_status)
                    @include('networks.peppol.status', ['status' => $document->reporting_status])
                @endif
            </div>
        </div>
        @empty
        <div class="p-6 text-center text-muted">{{ __('No e-invoices yet') }}</div>
        @endforelse
    </div>
</div>
