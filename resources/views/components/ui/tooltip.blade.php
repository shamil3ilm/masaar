@props(['text', 'label' => null])

{{--
    A short note shown on hover, on focus and on a long press.

    Never the only way to read it: the note is the trigger's description
    (aria-describedby), so a screen reader reads it with the trigger, and it
    stays in the page's text. Shown by CSS alone on hover and focus, so it
    works without script; resources/js/tooltip.js adds the long press a
    touch screen needs and Escape to put it away.

    The slot is the trigger - its first focusable element is described. With
    no slot, an information button is drawn, named by label.

    text   the note
    label  the information button's name; "More information" if not given
--}}
@php($id = 'tip-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(8)))
<span data-tooltip data-tooltip-for="{{ $id }}" {{ $attributes->merge(['class' => 'group/tip relative inline-flex items-center align-middle']) }}>
    @if ($slot->isEmpty())
        <button type="button" aria-describedby="{{ $id }}" aria-label="{{ $label ?? __('More information') }}"
                class="inline-flex size-11 items-center justify-center rounded-full text-subtle hover:text-fg focus-visible:outline-2 focus-visible:outline-focus -m-3">
            <x-ui.icon name="info" class="size-4" />
        </button>
    @else
        {{ $slot }}
    @endif
    <span role="tooltip" id="{{ $id }}"
          class="pointer-events-none invisible absolute bottom-full start-1/2 z-50 mb-2 w-max max-w-[min(18rem,calc(100vw-2rem))] -translate-x-1/2 rounded-control bg-ink-900 px-3 py-2 text-xs font-normal leading-snug text-white opacity-0 shadow-raised transition-opacity duration-150 group-hover/tip:visible group-hover/tip:opacity-100 group-focus-within/tip:visible group-focus-within/tip:opacity-100 group-data-shown/tip:visible group-data-shown/tip:opacity-100 group-data-dismissed/tip:invisible group-data-dismissed/tip:opacity-0 rtl:translate-x-1/2 dark:bg-sand-100 dark:text-ink-950">{{ $text }}</span>
</span>
