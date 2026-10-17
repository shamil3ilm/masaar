@props(['title' => null, 'description' => null, 'flush' => false])

{{--
    A surface on the page: a panel of settings, a table, a summary.

    One shape for what forty screens drew as "rounded-lg bg-white shadow" with
    a dark: pair each, in three radii. A card is a line and a surface, not a
    shadow: on warm paper a hairline reads as an edge, and the few things that
    float above the page (a menu, a dialog, the invoice on the home page) are
    the ones that get elevation.

    title / description  a header row, divided from the body.
    actions              a slot for buttons at the end of the header row.
    flush                no padding in the body, for a table that runs to
                         the card's edges.
--}}
<section {{ $attributes->merge(['class' => 'rounded-card border border-line bg-surface shadow-card']) }}>
    @if ($title || isset($actions))
        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-line px-5 py-4">
            <div class="min-w-0">
                @if ($title)
                    <h2 class="text-base font-semibold text-fg">{{ $title }}</h2>
                @endif
                @if ($description)
                    <p class="mt-0.5 text-sm text-subtle">{{ $description }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
            @endisset
        </header>
    @endif

    <div @class(['p-5' => ! $flush])>
        {{ $slot }}
    </div>
</section>
