{{-- Built for how you sell: the kinds of business the portal serves, as Admin › Website words them. --}}
<section id="business" class="relative scroll-mt-20 py-14 sm:py-16" aria-labelledby="business-title">
    <span class="route-stop" aria-hidden="true"></span>
    <x-ui.section-heading id="business-title" :eyebrow="$section->text('eyebrow')"
        :title="$section->text('title')" :pair="$section->pair('title')" />

    <div class="mt-8 grid border-t border-line sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($section->items('items') as $item)
            <div class="border-b border-line py-6 sm:pe-6">
                <h3 class="text-base font-semibold">{{ $item['title'] }}</h3>
                <p class="mt-2 text-sm leading-relaxed text-muted">{{ $section->rich($item['detail']) }}</p>
            </div>
        @endforeach
    </div>
</section>
