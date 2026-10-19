@props(['id', 'title'])

{{--
    A dialog over the page.

    The browser's own <dialog>, opened modally by resources/js/ui.js: it
    already keeps focus inside, closes on Escape, puts the page behind it out
    of reach of a screen reader and draws above everything else - four things
    a div has to be taught and usually is not.

    Opened by any element with data-modal-open="{id}", closed by any inside it
    with data-modal-close, or by a click on the backdrop. Focus returns to
    whatever opened it.

    @slot footer  the dialog's buttons, at its end.
--}}
<dialog id="{{ $id }}" aria-labelledby="{{ $id }}-title" data-modal
        {{ $attributes->merge(['class' => 'm-auto w-[calc(100%-2rem)] max-w-lg rounded-card border border-line bg-surface p-0 text-fg shadow-raised backdrop:bg-ink-950/60']) }}>
    <div class="flex items-start justify-between gap-4 border-b border-line px-6 py-4">
        <h2 id="{{ $id }}-title" class="text-lg font-semibold">{{ $title }}</h2>
        <button type="button" data-modal-close aria-label="{{ __('Close') }}"
                class="-me-2 -mt-1 inline-flex size-11 items-center justify-center rounded-button text-subtle hover:bg-sunken hover:text-fg focus-visible:outline-2 focus-visible:outline-focus">
            <x-ui.icon name="x" class="size-5" />
        </button>
    </div>
    <div class="px-6 py-5 text-sm text-muted">
        {{ $slot }}
    </div>
    @isset($footer)
        <div class="flex flex-col-reverse gap-2 border-t border-line px-6 py-4 sm:flex-row sm:justify-end">
            {{ $footer }}
        </div>
    @endisset
</dialog>
