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
        <span class="text-muted">
            @if ($einvoicingStart)
                {{ __('From :date, every invoice you issue is sent through your service provider.', ['date' => $einvoicingStart->toDateString()]) }}
            @else
                {{ __('Your invoices are tax invoices kept here and not sent. Set your e-invoicing start date on your company page.') }}
            @endif
        </span>
    @endif
</div>

@if ($startReminder)
    <x-ui.alert tone="warning" class="mb-6" data-einvoicing-reminder>
        {{ __('Your e-invoicing starts on :date, in :days days, and no service provider is recorded as appointed. Appoint one through EmaraTax and record it on your company page: from that date invoices cannot be issued without it.', ['date' => $startReminder['date']->toDateString(), 'days' => $startReminder['days']]) }}
        <a href="{{ route('portal.company.edit') }}" class="text-accent hover:text-accent-strong">{{ __('Record your service provider') }}</a>
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
    <div class="bg-surface rounded-card border border-line shadow-card p-6">
        <p class="text-sm text-muted">{{ __('Delivered') }}</p>
        <p class="text-3xl font-semibold text-success">{{ $einvoices['delivered'] }}</p>
    </div>
    <div class="bg-surface rounded-card border border-line shadow-card p-6">
        <p class="text-sm text-muted">{{ __('Awaiting delivery') }}</p>
        <p class="text-3xl font-semibold text-fg">{{ $einvoices['awaiting'] }}</p>
    </div>
    <div class="bg-surface rounded-card border border-line shadow-card p-6">
        <p class="text-sm text-muted">{{ __('Rejected') }}</p>
        <p class="text-3xl font-semibold {{ $einvoices['rejected'] > 0 ? 'text-danger' : 'text-subtle' }}">{{ $einvoices['rejected'] }}</p>
    </div>
    <div class="bg-surface rounded-card border border-line shadow-card p-6">
        <p class="text-sm text-muted">{{ __('Not sent') }}</p>
        <p class="text-3xl font-semibold {{ $einvoices['failed'] > 0 ? 'text-warning' : 'text-subtle' }}">{{ $einvoices['failed'] }}</p>
    </div>
    <div class="bg-surface rounded-card border border-line shadow-card p-6">
        <p class="text-sm text-muted">{{ __('Reported to the authority') }}</p>
        <p class="text-3xl font-semibold text-success">{{ $einvoices['reported'] }}</p>
    </div>
    <div class="bg-surface rounded-card border border-line shadow-card p-6">
        <p class="text-sm text-muted">{{ __('Report to the authority failed') }}</p>
        <p class="text-3xl font-semibold {{ $einvoices['report_failed'] > 0 ? 'text-danger' : 'text-subtle' }}">{{ $einvoices['report_failed'] }}</p>
    </div>
    <div class="bg-surface rounded-card border border-line shadow-card p-6">
        <p class="text-sm text-muted">{{ __('Reported only, not exchanged') }}</p>
        <p class="text-3xl font-semibold text-fg">{{ $einvoices['not_exchanged'] }}</p>
    </div>
    <div class="bg-surface rounded-card border border-line shadow-card p-6">
        <p class="text-sm text-muted">{{ __('Issued, not transmitted: before e-invoicing start') }}</p>
        <p class="text-3xl font-semibold text-fg">{{ $einvoices['not_transmitted'] }}</p>
    </div>
    <div class="bg-surface rounded-card border border-line shadow-card p-6">
        <p class="text-sm text-muted">{{ __('Received invoices') }}</p>
        <p class="text-3xl font-semibold text-fg">{{ $einvoices['received'] }}</p>
        {{-- The received screens are a UAE organization's. --}}
        @if (\App\Domains\Compliance\Countries\UnitedArabEmirates\Support\UaePortal::offers($organization))
            <a href="{{ route('portal.received.index') }}" class="text-accent hover:text-accent-strong text-sm">{{ __('View') }}</a>
        @endif
    </div>
</div>

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
