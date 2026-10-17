{{--
    A UAE organization's dashboard figures: its invoices, and where their
    e-invoices are (UaeDocuments::counts). Delivered includes those the
    authority has confirmed; Not sent is a document the queue gave up on.

    @param \App\Domains\Organization\Models\Organization $organization
    @param array $stats             invoices_today, invoices_month
    @param array $einvoices         awaiting, delivered, rejected, failed, received
    @param \Illuminate\Support\Collection $recentDocuments  latest sent PeppolDocuments
--}}
<div class="grid grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <div class="bg-white dark:bg-gray-900 rounded-lg shadow p-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Today\'s Invoices') }}</p>
        <p class="text-3xl font-bold text-gray-800 dark:text-gray-100">{{ $stats['invoices_today'] }}</p>
    </div>
    <div class="bg-white dark:bg-gray-900 rounded-lg shadow p-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('This Month') }}</p>
        <p class="text-3xl font-bold text-gray-800 dark:text-gray-100">{{ $stats['invoices_month'] }}</p>
    </div>
    <div class="bg-white dark:bg-gray-900 rounded-lg shadow p-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Delivered') }}</p>
        <p class="text-3xl font-bold text-green-600 dark:text-green-400">{{ $einvoices['delivered'] }}</p>
    </div>
    <div class="bg-white dark:bg-gray-900 rounded-lg shadow p-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Awaiting delivery') }}</p>
        <p class="text-3xl font-bold text-gray-800 dark:text-gray-100">{{ $einvoices['awaiting'] }}</p>
    </div>
    <div class="bg-white dark:bg-gray-900 rounded-lg shadow p-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Rejected') }}</p>
        <p class="text-3xl font-bold {{ $einvoices['rejected'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-400 dark:text-gray-500' }}">{{ $einvoices['rejected'] }}</p>
    </div>
    <div class="bg-white dark:bg-gray-900 rounded-lg shadow p-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Not sent') }}</p>
        <p class="text-3xl font-bold {{ $einvoices['failed'] > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-400 dark:text-gray-500' }}">{{ $einvoices['failed'] }}</p>
    </div>
    <div class="bg-white dark:bg-gray-900 rounded-lg shadow p-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Received invoices') }}</p>
        <p class="text-3xl font-bold text-gray-800 dark:text-gray-100">{{ $einvoices['received'] }}</p>
        {{-- The received screens are a UAE organization's. --}}
        @if (\App\Domains\Compliance\Countries\UnitedArabEmirates\Support\UaePortal::offers($organization))
            <a href="{{ route('portal.received.index') }}" class="text-teal-700 dark:text-teal-300 hover:text-teal-900 dark:hover:text-teal-200 text-sm">{{ __('View') }}</a>
        @endif
    </div>
</div>

<div class="bg-white dark:bg-gray-900 rounded-lg shadow">
    <div class="p-6 border-b flex justify-between items-center">
        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ __('Recent e-invoices') }}</h3>
        <a href="{{ route('portal.invoices.index') }}"
           class="text-teal-700 dark:text-teal-300 hover:text-teal-900 dark:hover:text-teal-200 text-sm">{{ __('View All') }}</a>
    </div>
    <div class="divide-y">
        @forelse ($recentDocuments as $document)
        <div class="p-4 flex items-center justify-between">
            <div>
                @if ($document->invoice_id)
                    <a href="{{ route('portal.invoices.show', $document->invoice_id) }}" class="text-sm font-medium text-teal-700 dark:text-teal-300 hover:underline">{{ Str::limit($document->invoice_id, 8) }}</a>
                @endif
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ \Carbon\Carbon::parse($document->created_at)->diffForHumans() }}</p>
            </div>
            @include('portal.partials.peppol-status', ['status' => $document->status])
        </div>
        @empty
        <div class="p-6 text-center text-gray-500 dark:text-gray-400">{{ __('No e-invoices yet') }}</div>
        @endforelse
    </div>
</div>
