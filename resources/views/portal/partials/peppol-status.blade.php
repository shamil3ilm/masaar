{{--
    Where a UAE e-invoice is on its way to the buyer, or with its report to
    the authority, as a pill.

    The states are DocumentStatus's, on either track: delivery (ready ...
    rejected, received) and the report (pending, reported, report_failed). Their names and meanings are said here
    rather than added to x-ui.badge's map, because "sent" and "delivered"
    mean something only for a Peppol document; the colour still comes from
    the badge's tones, so success, refusal and waiting look as they do
    everywhere else.

    @param string|null $status  a DocumentStatus value
--}}
@php
    [$label, $tone] = match ((string) $status) {
        'ready' => [__('Ready to send'), 'info'],
        'sending' => [__('Sending'), 'info'],
        'sent' => [__('Sent'), 'info'],
        'delivered' => [__('Delivered'), 'success'],
        'pending' => [__('Awaiting the authority'), 'info'],
        'reported' => [__('Reported to the authority'), 'success'],
        'report_failed' => [__('Report to the authority failed'), 'danger'],
        'rejected' => [__('Rejected'), 'danger'],
        'failed' => [__('Not sent'), 'warning'],
        'received' => [__('Received'), 'success'],
        default => [__('Unknown'), 'neutral'],
    };
@endphp
<x-ui.badge :state="$status" :tone="$tone" :label="$label" />
