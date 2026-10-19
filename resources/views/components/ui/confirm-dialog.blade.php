{{--
    The question asked before something that changes or destroys.

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

    Rendered once per layout and driven by resources/js/confirm.js from the
    asking form's data attributes (the anatomy is in confirm.js and on Admin ›
    Design system). The markup is here rather than built in JavaScript so it
    uses x-ui.button like everything else, and so every word in it is
    translated on the server: the approval note and the typing prompt would
    otherwise be English on an Arabic page.

    Both accept buttons exist and one is hidden, rather than one button whose
    classes are swapped: Tailwind finds its classes by reading the source, so
    a class string assembled at runtime is not in the stylesheet.
--}}
<div data-confirm-dialog hidden class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center">
    <div data-confirm-backdrop class="glass-scrim absolute inset-0"></div>

    <div role="dialog" aria-modal="true" aria-labelledby="confirm-title" aria-describedby="confirm-message"
         class="relative max-h-full w-full max-w-md overflow-y-auto rounded-card border border-line bg-surface p-6 shadow-raised">
        <h2 id="confirm-title" data-confirm-title class="text-lg font-semibold text-fg">{{ __('Are you sure?') }}</h2>

        <p id="confirm-message" data-confirm-message class="mt-2 text-sm text-muted"></p>

        {{-- Shown when the form carries the approval reason, which it does
             only while that action's approval policy is on. --}}
        <x-ui.alert tone="info" data-confirm-approval hidden class="mt-4">
            {{ __('This will be sent for approval. Nothing changes until it is approved.') }}
        </x-ui.alert>

        {{-- The irreversible few: accept stays disabled until the word is
             typed. :word is filled in by confirm.js. --}}
        <div data-confirm-typed hidden class="mt-4">
            <label class="block text-sm font-medium text-fg">
                <span data-confirm-typed-label data-template="{{ __('Type :word to confirm.') }}"></span>
                <x-ui.input type="text" data-confirm-typed-input autocomplete="off" autocapitalize="off" spellcheck="false" />
            </label>
        </div>

        {{-- Reverse on a narrow screen so the safe answer is nearest the
             thumb. Cancel is first in the DOM, so it leads for the keyboard. --}}
        <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <x-ui.button type="button" variant="ghost" data-confirm-cancel>{{ __('Cancel') }}</x-ui.button>

            <x-ui.button type="button" data-confirm-accept data-tone="primary">{{ __('Confirm') }}</x-ui.button>

            <x-ui.button type="button" variant="danger" data-confirm-accept data-tone="danger" hidden>
                {{ __('Confirm') }}
            </x-ui.button>
        </div>
    </div>
</div>
