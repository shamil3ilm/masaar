@props(['id', 'label', 'tabs', 'selected' => null])

{{--
    Views of one thing, one at a time: a country's rules, a period's report.

    The ARIA tabs pattern, driven by resources/js/ui.js: the tabs are one stop
    for Tab, and the arrow keys move between them (reversed in Arabic, where
    the row runs the other way), Home and End go to the ends. Each tab names
    the panel it controls and each panel names its tab.

    tabs      [key => label], in order
    selected  the key open at first; the first when not given
    The slot holds one <x-ui.tab-panel> per key, with the same id and key.
--}}
@php
    $selected ??= array_key_first($tabs);
@endphp

<div data-tabs {{ $attributes }}>
    <div role="tablist" aria-label="{{ $label }}" class="flex flex-wrap gap-2">
        @foreach ($tabs as $key => $text)
            <button type="button" role="tab" id="{{ $id }}-tab-{{ $key }}" aria-controls="{{ $id }}-panel-{{ $key }}"
                    aria-selected="{{ $key === $selected ? 'true' : 'false' }}" tabindex="{{ $key === $selected ? '0' : '-1' }}"
                    class="inline-flex min-h-11 items-center rounded-full border border-line-strong bg-surface px-5 text-sm font-medium text-fg transition-colors hover:border-subtle focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus aria-selected:border-action aria-selected:bg-action aria-selected:text-on-action">
                {{ $text }}
            </button>
        @endforeach
    </div>
    <div class="mt-5">
        {{ $slot }}
    </div>
</div>
