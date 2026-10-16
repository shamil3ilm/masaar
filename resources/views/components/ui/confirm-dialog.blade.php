{{--
    The question asked before something irreversible.

    Nine forms asked it through window.confirm(), which is the browser's
    dialog and not ours: its own typography, its own button order, a title bar
    naming the host, and no way to say which button is the dangerous one. On
    "Revoke this licence? This is permanent" the destructive answer looked
    exactly like the safe one.

    It is also inconsistent in a way that matters more than looks. The browser
    decides the button order by platform, so the same screen puts Confirm on
    the left on one machine and the right on another - and a person who has
    learned where "yes" is on a screen that revokes a licence should not have
    to read it again on a different computer.

    Rendered once per layout and driven by resources/js/confirm.js. The markup
    is here rather than built in JavaScript so it uses x-ui.button like
    everything else, and so ActionMarkupTest covers it.

    Both accept buttons exist and one is hidden, rather than one button whose
    classes are swapped: Tailwind finds its classes by reading the source, so
    a class string assembled at runtime is not in the stylesheet.
--}}
<div data-confirm-dialog hidden class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center">
    <div data-confirm-backdrop class="absolute inset-0 bg-ink-950/60"></div>

    <div role="dialog" aria-modal="true" aria-labelledby="confirm-title"
         class="relative w-full max-w-md rounded-lg bg-white dark:bg-gray-900 p-6 shadow-xl">
        <h2 id="confirm-title" class="text-lg font-semibold text-ink-900 dark:text-gray-100">{{ __('Are you sure?') }}</h2>

        <p data-confirm-message class="mt-2 text-sm text-gray-600 dark:text-gray-300"></p>

        {{-- Reverse on a narrow screen so the safe answer is nearest the
             thumb, and Cancel first in the DOM so it takes focus on open. --}}
        <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <x-ui.button type="button" variant="quiet" data-confirm-cancel>{{ __('Cancel') }}</x-ui.button>

            <x-ui.button type="button" data-confirm-accept data-tone="primary">{{ __('Confirm') }}</x-ui.button>

            <x-ui.button type="button" variant="danger" data-confirm-accept data-tone="danger" hidden>
                {{ __('Confirm') }}
            </x-ui.button>
        </div>
    </div>
</div>
