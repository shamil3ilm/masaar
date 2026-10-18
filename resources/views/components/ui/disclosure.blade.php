@props(['summary', 'open' => false])

{{--
    A question and its answer, or any detail that can wait to be opened.

    The browser's own <details>: keyboard, screen reader and find-in-page all
    work without script, and a closed answer still opens when someone
    searches the page for a word in it. The marker is a plus that turns into a
    minus, drawn here rather than the browser's triangle, which points the
    wrong way in Arabic in some browsers.
--}}
<details @if ($open) open @endif {{ $attributes->merge(['class' => 'group border-b border-line py-5']) }}>
    <summary class="flex min-h-11 list-none items-center justify-between gap-4 rounded text-base font-semibold text-fg [&::-webkit-details-marker]:hidden">
        <span>{{ $summary }}</span>
        <span class="relative size-4 shrink-0 text-accent" aria-hidden="true">
            <span class="absolute inset-x-0 top-1/2 h-0.5 -translate-y-1/2 rounded bg-current"></span>
            <span class="absolute inset-y-0 start-1/2 w-0.5 -translate-x-1/2 rounded bg-current transition-transform group-open:scale-y-0 rtl:translate-x-1/2"></span>
        </span>
    </summary>
    <div class="mt-3 max-w-3xl text-sm leading-relaxed text-muted">
        {{ $slot }}
    </div>
</details>
