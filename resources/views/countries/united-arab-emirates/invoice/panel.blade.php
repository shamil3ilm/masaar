{{--
    What became of a UAE invoice's e-invoice, beside the invoice's page
    (Presentation::invoicePage).

    The status is the document's (DocumentStatus), the message id the one the
    service provider gave it, and the errors what the provider or the buyer's
    provider said when they refused it.

    @param $invoice   \App\Domains\Invoice\Models\Invoice
    @param $document  \App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument|null
--}}
<div class="rounded-card bg-surface p-6 border border-line shadow-card">
    <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-muted">{{ __('UAE e-invoice') }}</h3>

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

        <dl class="space-y-3 text-sm">
            <div>
                <dt class="text-muted">{{ __('Status') }}</dt>
                <dd class="mt-1">@include('portal.partials.peppol-status', ['status' => $document->status])</dd>
            </div>
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
                    <dd>
                        <ul class="mt-1 list-disc space-y-1 ps-5 text-xs text-danger">
                            @foreach ((array) $document->errors as $error)
                                <li class="break-words">{{ is_scalar($error) ? $error : json_encode($error) }}</li>
                            @endforeach
                        </ul>
                    </dd>
                </div>
            @endif
            @if ($document->last_error)
                <div>
                    <dt class="text-muted">{{ __('Last error') }}</dt>
                    <dd class="break-words text-xs text-fg">{{ $document->last_error }}</dd>
                </div>
            @endif
        </dl>

        <div class="mt-4">
            <x-ui.button variant="secondary" :href="route('portal.invoices.xml', $invoice->id)">{{ __('Download e-invoice (XML)') }}</x-ui.button>
        </div>
    @endif
</div>
