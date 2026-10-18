@props(['label', 'align' => 'end'])

{{--
    A short list of actions behind one button: an account's options.

    The menu-button pattern, driven by resources/js/ui.js: the trigger says
    it opens a menu and whether it is open; Enter, Space or Down opens it on
    the first item, the arrow keys move through the items, Escape closes it
    and returns focus to the trigger, and so does a click anywhere else.

    label   the trigger's text - or, with a trigger slot, its accessible name.
    align   which edge the panel lines up with: end (default) or start.
    @slot trigger  what the trigger shows, when it is not just the label.
    The slot holds <x-ui.menu-item> entries.
--}}
<div data-menu class="relative inline-block" {{ $attributes }}>
    <button type="button" data-menu-trigger aria-haspopup="menu" aria-expanded="false"
            @isset($trigger) aria-label="{{ $label }}" @endisset
            class="inline-flex min-h-11 items-center gap-2 rounded-button px-3 text-sm font-medium text-fg transition-colors hover:bg-sunken focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
        {{ $trigger ?? $label }}
        <svg class="size-4 text-subtle" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6" /></svg>
    </button>
    <div role="menu" data-menu-panel hidden
         @class([
             'absolute z-50 mt-1 min-w-56 rounded-tile border border-line bg-surface p-1 shadow-raised',
             'end-0' => $align === 'end',
             'start-0' => $align !== 'end',
         ])>
        {{ $slot }}
    </div>
</div>
