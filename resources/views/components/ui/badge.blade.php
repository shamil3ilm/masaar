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
        'failed', 'suspended', 'expiring_soon' => 'warning',

        // On its way.
        'queued', 'queued_offline', 'pending', 'pending_submission', 'pending_activation',
        'processing', 'submitted', 'issued', 'csr_generated' => 'info',

        // Accepted with warnings: the authority has it, and said something.
        'warning' => 'warning',

        // Never issued. A Phase 1 organization has no certificate and that is
        // not a fault, so it is not coloured as one.
        'missing', 'unknown' => 'neutral',

        default => 'neutral',
    };

    $classes = match ($meaning) {
        'success' => 'bg-green-100 dark:bg-green-900/40 text-green-800 dark:text-green-200',
        'danger' => 'bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200',
        'warning' => 'bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-200',
        'info' => 'bg-blue-100 dark:bg-blue-900/40 text-blue-800 dark:text-blue-200',
        default => 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-200',
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
        'in_progress' => __('In progress'),
        'passed' => __('Passed'),
        'warning' => __('Warning'),
        'missing' => __('Missing'),
        '', 'unknown' => __('Unknown'),
        default => null,
    };
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex rounded-full px-2 py-0.5 text-xs font-semibold '.$classes]) }}>
    {{-- Underscores are a database detail. 'pending_submission' is not a word. --}}
    {{ $label ?? $name ?? ucfirst(str_replace('_', ' ', $value)) }}
</span>
