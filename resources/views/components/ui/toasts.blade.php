{{--
    Where short-lived messages appear: the corner of the screen, above
    everything, for a moment.

    A toast is an x-ui.alert - the same tones, icon and role - so it is the
    flash message's twin rather than a new kind of thing; the templates
    below are cloned by resources/js/toast.js. For a message about something
    done in place, without a page load: a copy, a background save, an upload
    that failed. What a submitted form has to say stays in the flash at the
    top of the page, where it is read with the page.

    Any button with data-toast="message" (and data-toast-tone) shows one.
    One per layout.
--}}
<div data-toasts aria-live="polite"
     class="pointer-events-none fixed inset-x-4 bottom-4 z-[70] flex flex-col items-stretch gap-2 sm:inset-x-auto sm:end-4 sm:w-96">
    @foreach (['success', 'warning', 'danger', 'info'] as $tone)
        <template data-toast-template="{{ $tone }}">
            <div class="pointer-events-auto relative rounded-tile shadow-raised" data-toast-item>
                <x-ui.alert :tone="$tone" class="pe-12"><span data-toast-text></span></x-ui.alert>
                <button type="button" data-toast-close aria-label="{{ __('Dismiss') }}"
                        class="absolute end-1 top-1 inline-flex size-9 items-center justify-center rounded-button text-subtle hover:text-fg focus-visible:outline-2 focus-visible:outline-focus">
                    <x-ui.icon name="x" class="size-4" />
                </button>
            </div>
        </template>
    @endforeach
</div>
