@props(['title', 'description' => null, 'eyebrow' => null])

{{--
    The top of a screen: what it is, a line on what it is for, and the
    actions that belong to the whole screen.

    Fifty screens opened with an h2 in text-2xl font-bold and a grey
    paragraph, each written out, in three spacings. This is the one heading
    level a screen has - h1 - so a screen reader's list of headings starts
    with the screen's name rather than with the layout's. ScreenRenderTest
    holds every screen to exactly one (data-page-header).

    eyebrow      a small line above the title: the section it sits in. A
                 slot when a country draws it (the kind of invoice).
    description  a line on what the screen is for. A plain string as an
                 attribute, or a slot when it carries a link or a code.
    @slot actions        buttons, at the end edge on a wide screen and under
                         the title on a phone. The primary action goes last,
                         nearest the edge.
    @slot breadcrumb     an <x-ui.breadcrumb>, above everything - the way
                         back up, in place of a hand-written back link.
    @slot meta           badges beside the title: its state, not actions.
--}}
<div data-page-header {{ $attributes->merge(['class' => 'mb-6']) }}>
    @isset($breadcrumb)
        <div class="mb-3">{{ $breadcrumb }}</div>
    @endisset
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            @if ($eyebrow instanceof \Illuminate\View\ComponentSlot)
                <div>{{ $eyebrow }}</div>
            @elseif ($eyebrow)
                <p class="text-eyebrow font-semibold uppercase text-accent">{{ $eyebrow }}</p>
            @endif
            <div @class(['flex flex-wrap items-center gap-x-3 gap-y-1', 'mt-1' => $eyebrow])>
                <h1 class="min-w-0 break-words text-2xl font-semibold tracking-tight text-fg">{{ $title }}</h1>
                @isset($meta)
                    {{ $meta }}
                @endisset
            </div>
            @if ($description instanceof \Illuminate\View\ComponentSlot ? $description->isNotEmpty() : filled($description))
                <p class="mt-1 max-w-3xl text-sm text-muted">{{ $description }}</p>
            @endif
        </div>
        @isset($actions)
            <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
        @endisset
    </div>
</div>
