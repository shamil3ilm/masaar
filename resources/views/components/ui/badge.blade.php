@props(['state' => null, 'tone' => null, 'label' => null])

{{--
    A state, in the one colour that state is.

    Eighteen screens decided this inline and they did not agree. portal/submissions
    and admin/logs painted `failed` orange; admin/queue painted it red - and red
    on those same screens means `rejected`, which is the authority refusing the
    document. So a queue item that had simply not been answered yet read as one
    the authority had turned down, which is the distinction between "retry this"
    and "issue a credit note".

    The tones are meanings rather than colours, and the map below is the only
    place a state becomes one:

      success  the authority has it, or the work is done
      danger   refused, by the authority or by us - do not retry as-is
      warning  no answer yet, and something should be watched
      info     on its way, nothing wrong
      neutral  not started, or no longer relevant

    An unknown state is neutral rather than invisible. The inline versions
    emitted a pill with no background at all for anything they did not list,
    so a new state appeared as grey text on white and read as a rendering bug.

    The certificate pill on admin/organization-detail went further and decided
    nothing: it printed whatever status it was handed on a fixed green
    background, so a revoked or expired certificate read as a healthy one.

    tone overrides the lookup, for a pill whose subject is not a state at all
    - a boolean, or a number of days. Everything with a state passes state and
    gets the colour that state has everywhere else.
--}}
@php
    $value = $state instanceof \BackedEnum ? $state->value : (string) $state;

    $meaning = $tone ?? match ($value) {
        // The authority has the document, or the job is finished.
        'cleared', 'reported', 'accepted', 'completed', 'active', 'compliance_passed' => 'success',

        // Refused, or run out. Nothing more happens without an action.
        'rejected', 'revoked', 'cancelled', 'expired' => 'danger',

        // No verdict. Worth watching, and usually worth retrying.
        'failed', 'suspended', 'expiring_soon', 'paused' => 'warning',

        // On its way.
        'queued', 'queued_offline', 'pending', 'pending_submission', 'pending_activation',
        'processing', 'submitted', 'issued', 'csr_generated', 'drafted' => 'info',

        // Accepted with warnings: the authority has it, and said something.
        'warning' => 'warning',

        // Never issued. A Phase 1 organization has no certificate and that is
        // not a fault, so it is not coloured as one.
        'missing', 'unknown', 'skipped' => 'neutral',

        default => 'neutral',
    };

    $classes = match ($meaning) {
        'success' => 'border-success-line bg-success-soft text-success',
        'danger' => 'border-danger-line bg-danger-soft text-danger',
        'warning' => 'border-warning-line bg-warning-soft text-warning',
        'info' => 'border-info-line bg-info-soft text-info',
        default => 'border-line-strong bg-sunken text-muted',
    };

    // Each known state's name, written out so it can be translated.
    $name = match ($value) {
        'cleared' => __('Cleared'),
        'reported' => __('Reported'),
        'accepted' => __('Accepted'),
        'completed' => __('Completed'),
        'active' => __('Active'),
        'compliance_passed' => __('Compliance passed'),
        'rejected' => __('Rejected'),
        'revoked' => __('Revoked'),
        'cancelled' => __('Cancelled'),
        'expired' => __('Expired'),
        'failed' => __('Failed'),
        'suspended' => __('Suspended'),
        'expiring_soon' => __('Expiring soon'),
        'queued' => __('Queued'),
        'queued_offline' => __('Queued offline'),
        'pending' => __('Pending'),
        'pending_submission' => __('Pending submission'),
        'pending_activation' => __('Pending activation'),
        'pending_review' => __('Pending review'),
        'processing' => __('Processing'),
        'submitted' => __('Submitted'),
        'issued' => __('Issued'),
        'draft' => __('Draft'),
        'drafted' => __('Draft created'),
        'paused' => __('Paused'),
        'skipped' => __('Skipped'),
        'in_progress' => __('In progress'),
        'passed' => __('Passed'),
        'warning' => __('Warning'),
        'missing' => __('Missing'),
        '', 'unknown' => __('Unknown'),
        default => null,
    };
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border px-2.5 py-0.5 text-xs font-semibold before:size-1.5 before:rounded-full before:bg-current '.$classes]) }}>
    {{-- Underscores are a database detail. 'pending_submission' is not a word. --}}
    {{ $label ?? $name ?? ucfirst(str_replace('_', ' ', $value)) }}
</span>
