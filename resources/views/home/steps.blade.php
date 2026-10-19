{{-- How it works: from signing up to the first invoice. The last step is the one the page is for. Words from Admin › Website. --}}
<section id="steps" class="relative scroll-mt-20 py-14 sm:py-16" aria-labelledby="steps-title">
    <span class="route-stop" aria-hidden="true"></span>
    <x-ui.section-heading id="steps-title" :eyebrow="$section->text('eyebrow')"
        :title="$section->text('title')" :pair="$section->pair('title')" />

    <ol class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($section->items('items') as $item)
            <li @class([
                'rounded-tile p-6',
                'border border-line bg-surface' => ! $loop->last,
                'bg-ink-900 text-white' => $loop->last,
            ])>
                <span @class(['font-mono text-sm font-semibold tabular-nums', 'text-accent' => ! $loop->last, 'text-teal-300' => $loop->last]) dir="ltr">{{ sprintf('%02d', $loop->iteration) }}</span>
                <p class="mt-3 leading-relaxed">{{ $section->rich($item['text']) }}</p>
            </li>
        @endforeach
    </ol>
</section>
