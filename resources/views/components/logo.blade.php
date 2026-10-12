{{--
    The Masaar mark.

    Drawn inline rather than loaded from a file so it costs no request, needs
    no Vite asset, and is one less origin for the content policy to allow. It
    is four shapes; a sprite would be heavier than the thing it fetches.

    The geometry is the same as the ERP front-end's Logo.tsx, so the two
    products show the same mark. If that drawing changes, this changes with
    it.

    - tile: show the navy tile behind the path. Off when the mark sits on a
      navy surface of its own, where the tile would be invisible anyway.
    - size: rendered width and height in pixels.
    - name: show the wordmark beside it.
    - nameClass: how the wordmark is sized and weighted.

    The wordmark is sized by a class rather than a style attribute. An inline
    style would have to be permitted by the content policy, and permitting it
    anywhere permits it everywhere - a whole directive relaxed to set one font
    size.
--}}
@props([
    'size' => 32,
    'tile' => true,
    'name' => false,
    'nameClass' => 'text-lg font-semibold tracking-tight',
])

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2.5']) }}>
    <svg
        width="{{ (int) $size }}"
        height="{{ (int) $size }}"
        viewBox="0 0 120 120"
        xmlns="http://www.w3.org/2000/svg"
        class="shrink-0"
        @if ($name) aria-hidden="true" @else role="img" aria-label="Masaar" @endif
    >
        @if ($tile)
            <rect x="10" y="10" width="100" height="100" rx="20" fill="#1A1F36" />
        @endif

        {{-- The path: masaar means route, and this is one. --}}
        <path
            d="M30 75 C40 40, 60 40, 70 75 S100 100, 95 55"
            stroke="#14B8A6"
            stroke-width="4"
            stroke-linecap="round"
            fill="none"
        />

        {{-- Where it arrives. --}}
        <circle cx="95" cy="55" r="4" fill="#EADBC8" />
    </svg>

    @if ($name)
        <span class="{{ $nameClass }}">Masaar</span>
    @endif
</span>
