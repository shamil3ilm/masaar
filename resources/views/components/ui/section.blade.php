@props(['title' => null, 'description' => null, 'collapse' => false, 'closed' => false, 'flush' => false])

{{--
    A titled part of a long page or form - a card whose heading can fold it.

    The browser's own <details>, so it opens and closes by keyboard and
    screen reader without script, and find-in-page still reaches a closed
    one. Open by default everywhere.

    collapse  a part that can wait on a small screen: an optional part of a
              form, a secondary part of a detail page. resources/js/ui.js
              closes it below 640px - unless it holds a field with an error,
              which opens it, as does the browser finding a field in it
              invalid - and keeps it open on a wider one. The essentials of a
              page (totals, status, the QR, the main actions) are never put
              in one.
    closed    starts closed on every screen (the style guide's specimen).
    flush     no padding in the body, for a table that runs to its edges.
    @slot caption  a heading that is more than text - the bilingual
                   x-caption an invoice's buyer is headed with - in place
                   of title.
--}}
<details @unless ($closed) open @endunless @if ($collapse) data-section-collapse @endif
         {{ $attributes->merge(['class' => 'group/section rounded-card border border-line bg-surface shadow-card']) }}>
    <summary class="flex min-h-14 cursor-pointer list-none items-center justify-between gap-3 rounded-card px-5 py-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus [&::-webkit-details-marker]:hidden">
        <span class="min-w-0">
            <span class="block text-base font-semibold text-fg">{{ $caption ?? $title }}</span>
            @if ($description)
                <span class="mt-0.5 block text-sm text-subtle">{{ $description }}</span>
            @endif
        </span>
        <svg class="size-5 text-subtle transition-transform group-open/section:rotate-180" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6" /></svg>
    </summary>
    <div @class(['border-t border-line', 'p-5' => ! $flush])>
        {{ $slot }}
    </div>
</details>
