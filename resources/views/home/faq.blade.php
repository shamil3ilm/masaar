{{-- Questions asked before signing up, as Admin › Website words them. Every answer should be something the product does today. --}}
<section id="faq" class="relative scroll-mt-20 py-14 sm:py-16" aria-labelledby="faq-title">
    <span class="route-stop" aria-hidden="true"></span>
    <x-ui.section-heading id="faq-title" :eyebrow="$section->text('eyebrow')"
        :title="$section->text('title')" :pair="$section->pair('title')" />

    <div class="mt-6 border-t border-line">
        @foreach ($section->items('items') as $item)
            <x-ui.disclosure :summary="$item['question']" :open="$loop->first">{{ $section->rich($item['answer']) }}</x-ui.disclosure>
        @endforeach
    </div>
</section>
