{{--
    A UAE organization's dashboard figures (Reports\Dashboard::portal): its
    invoices, and where their e-invoices are (UaeDocuments::counts). Delivered
    includes those the authority has confirmed; Not sent is a document the
    queue gave up on.

    @param \App\Domains\Organization\Models\Organization $organization
    @param array $stats             invoices_today, invoices_month
    @param array $einvoices         awaiting, delivered, rejected, failed, received
    @param \Illuminate\Support\Collection $recentDocuments  latest sent PeppolDocuments
--}}
<div class="grid grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <div class="bg-surface rounded-card border border-line shadow-card p-6">
        <p class="text-sm text-muted">{{ __('Today\'s Invoices') }}</p>
        <p class="text-3xl font-semibold text-fg">{{ $stats['invoices_today'] }}</p>
    </div>
    <div class="bg-surface rounded-card border border-line shadow-card p-6">
        <p class="text-sm text-muted">{{ __('This Month') }}</p>
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
           class="text-accent hover:text-accent-strong text-sm">{{ __('View All') }}</a>
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
            @include('portal.partials.peppol-status', ['status' => $document->status])
        </div>
        @empty
        <div class="p-6 text-center text-muted">{{ __('No e-invoices yet') }}</div>
        @endforelse
    </div>
</div>
