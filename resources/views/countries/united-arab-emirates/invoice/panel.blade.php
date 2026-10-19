{{--
    What became of a UAE invoice's e-invoice, beside the invoice's page
    (Presentation::invoicePage).

    The statuses are the document's (DocumentStatus): its delivery and its
    report to the authority. The message id is the one the service provider
    gave it, the errors what the provider or the buyer's provider said when
    they refused it, and the authority reference and report errors what the
    provider relayed about the report.

    @param $invoice   \App\Domains\Invoice\Models\Invoice
    @param $document  \App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument|null
    @param $history   \Illuminate\Support\Collection of PeppolDocumentEvent, oldest first
--}}
<div class="rounded-card bg-surface p-6 border border-line shadow-card">
    <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-muted">{{ __('UAE e-invoice') }}</h3>

    {{-- PEPPOL_GATEWAY=simulator, local and staging only: said where the status is, so nobody takes it for a delivery. --}}
    @if (config('peppol.gateway') === 'simulator')
        <x-ui.alert tone="warning" class="mb-4" data-simulated>{{ __('Test mode: a service-provider simulator handles this e-invoice. Nothing is sent on the Peppol network or reported to the authority.') }}</x-ui.alert>
    @endif

    @if ($invoice->status === \App\Domains\Invoice\Enums\InvoiceStatus::Draft)
        <p class="text-sm text-muted">
            {{ __('This invoice is still a draft. When it is issued it takes its number and is sent to the buyer as a UAE e-invoice.') }}
        </p>
    @elseif ($document === null)
        <p class="text-sm text-muted">
            {{ __('No e-invoice has been made for this invoice.') }}
        </p>
    @else
        {{-- Issued past the UAE's 14 days (IssueDeadline): sent all the same. --}}
        @foreach (\App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\IssueDeadline::warnings($document) as $warning)
            <x-ui.alert tone="warning" class="mb-4" data-issued-late>{{ $warning }}</x-ui.alert>
        @endforeach

        @if (! empty($outage) && in_array($document->status, ['ready', 'sending'], true))
            <x-ui.alert tone="warning" class="mb-4" data-provider-outage>{{ __('The service provider is not answering at the moment. This e-invoice waits and is sent as soon as it answers again.') }}</x-ui.alert>
        @endif

        <dl class="space-y-3 text-sm">
            {{-- Delivery and the report to the authority are confirmed apart
                 (UAE Electronic Invoicing Guidelines v1.1, p10-11), so each has its own line. --}}
            <div>
                <dt class="text-muted">{{ __('Delivery to the buyer') }}</dt>
                <dd class="mt-1">@include('portal.partials.peppol-status', ['status' => $document->status])</dd>
            </div>
            <div>
                <dt class="text-muted">{{ __('Report to the authority') }}</dt>
                <dd class="mt-1">
                    @if ($document->reporting_status)
                        @include('portal.partials.peppol-status', ['status' => $document->reporting_status])
                    @else
                        <span class="text-xs text-muted">{{ __('Starts when the service provider takes the e-invoice') }}</span>
                    @endif
                </dd>
            </div>
            @if ($document->provider_reference)
                <div>
                    <dt class="text-muted">{{ __('Service provider reference') }}</dt>
                    <dd class="break-all font-mono text-xs text-fg">{{ $document->provider_reference }}</dd>
                </div>
            @endif
            @if ($document->authority_reference)
                <div>
                    <dt class="text-muted">{{ __('Authority reference') }}</dt>
                    <dd class="break-all font-mono text-xs text-fg">{{ $document->authority_reference }}</dd>
                </div>
            @endif
            @if (! empty($document->reporting_errors))
                <div>
                    <dt class="text-muted">{{ __('Why the report failed') }}</dt>
                    <dd>@include('portal.partials.peppol-errors', ['errors' => $document->errorList('reporting_errors')])</dd>
                </div>
            @endif
            @if ($document->document_uuid)
                <div>
                    <dt class="text-muted">{{ __('E-invoice UUID') }}</dt>
                    <dd class="break-all font-mono text-xs text-fg">{{ $document->document_uuid }}</dd>
                </div>
            @endif
            <div>
                <dt class="text-muted">{{ __('Message ID') }}</dt>
                <dd class="break-all font-mono text-xs text-fg">{{ $document->asp_message_id ?? __('Not sent yet') }}</dd>
            </div>
            @if ($document->mls_code)
                <div>
                    <dt class="text-muted">{{ __('Buyer\'s provider response') }}</dt>
                    <dd class="font-mono text-xs text-fg">{{ $document->mls_code }}</dd>
                </div>
            @endif
            @if (! empty($document->errors))
                <div>
                    <dt class="text-muted">{{ __('Errors') }}</dt>
                    <dd>@include('portal.partials.peppol-errors', ['errors' => $document->errorList('errors')])</dd>
                </div>
            @endif
            @if ($document->last_error)
                <div>
                    <dt class="text-muted">{{ __('Last error') }}</dt>
                    <dd class="break-words text-xs text-fg">{{ $document->last_error }}</dd>
                </div>
            @endif
        </dl>

        {{-- The transmission record (DocumentHistory): every move, callback
             and lookup, oldest first, kept whether it moved the e-invoice or not. --}}
        @if (isset($history) && $history->isNotEmpty())
            <div class="mt-4" data-transmission-record>
                <h4 class="mb-2 text-xs font-semibold uppercase tracking-wider text-muted">{{ __('Transmission record') }}</h4>
                <ol class="space-y-2 text-xs">
                    @foreach ($history as $event)
                        <li class="flex flex-wrap items-center gap-2">
                            <x-ui.date :value="$event->occurred_at" seconds :zone="\App\Support\CountryClock::timeZone($document->country)" class="text-muted" />
                            <span class="text-fg">{{ match ($event->source) {
                                'submission' => __('Handed to the provider'),
                                'callback' => __('Provider callback'),
                                'poll', 'lookup' => __('Status lookup'),
                                'retry' => __('Report asked again'),
                                'operator' => __('Operator'),
                                'backfill' => __('Earlier record'),
                                default => __('Platform'),
                            } }}</span>
                            @if ($event->status && $event->track !== 'reporting')
                                @include('portal.partials.peppol-status', ['status' => $event->status])
                            @endif
                            @if ($event->reporting_status)
                                @include('portal.partials.peppol-status', ['status' => $event->reporting_status])
                            @endif
                            @unless ($event->applied)
                                <span class="text-muted">{{ __('(no change)') }}</span>
                            @endunless
                        </li>
                    @endforeach
                </ol>
            </div>
        @endif

        <div class="mt-4">
            <x-ui.button variant="secondary" :href="route('portal.invoices.xml', $invoice->id)">{{ __('Download e-invoice (XML)') }}</x-ui.button>
        </div>
    @endif
</div>
