{{--
    What every account gets, wherever it files. What differs by country is
    on the country's tab above, from its own module.

    The words are Admin › Website's; each feature's icon is the one at its
    place in the list, so a feature added past the sixth reuses them in turn.
--}}
@php
    $icons = [
        'M14 3H6a2 2 0 00-2 2v14a2 2 0 002 2h12a2 2 0 002-2V9zM14 3v6h6M8 13h8M8 17h5',
        'M9 12l2 2 4-4M12 3l7 4v5c0 4.2-2.9 8-7 9-4.1-1-7-4.8-7-9V7z',
        'M12 21a9 9 0 100-18 9 9 0 000 18zM12 7v5l3 2',
        'M8 9l-4 3 4 3M16 9l4 3-4 3M14 5l-4 14',
        'M4 20V10M10 20V4M16 20v-7M22 20H2',
        'M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z',
    ];
@endphp
<section id="features" class="relative scroll-mt-20 py-14 sm:py-16" aria-labelledby="features-title">
    <span class="route-stop" aria-hidden="true"></span>
    <x-ui.section-heading id="features-title" :eyebrow="$section->text('eyebrow')"
        :title="$section->text('title')" :pair="$section->pair('title')" />

    <ul class="mt-8 grid gap-x-10 gap-y-8 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($section->items('items') as $item)
            <li class="flex gap-4">
                <svg class="size-7 shrink-0 text-teal-500" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons[$loop->index % count($icons)] }}" /></svg>
                <div>
                    <h3 class="text-base font-semibold">{{ $item['title'] }}</h3>
                    <p class="mt-1.5 text-sm leading-relaxed text-muted">{{ $section->rich($item['detail']) }}</p>
                </div>
            </li>
        @endforeach
    </ul>
</section>
