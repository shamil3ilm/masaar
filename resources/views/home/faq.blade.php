{{-- Questions asked before signing up. Every answer is something the product does today. --}}
<section id="faq" class="relative scroll-mt-20 py-14 sm:py-16" aria-labelledby="faq-title">
    <span class="route-stop" aria-hidden="true"></span>
    <x-ui.section-heading id="faq-title" :eyebrow="__('Questions')"
        :title="__('Asked often.')"
        :pair="__('Asked often.', [], $pair)" />

    <div class="mt-6 border-t border-line">
        @foreach ([
            [__('Do I need my own ERP?'), __('No. Every invoice can be issued in the portal. If you already have an ERP or an online store, connect it through the API instead.')],
            [__('What happens after the free month?'), __('We agree a plan with you, sized to how many invoices you issue and where you file. Nothing is charged without a plan you have agreed to.')],
            [__('What do I need to sign up?'), __('Your company\'s legal name, its tax registration and its address, as the authority holds them. The form asks only for what your country\'s rules need, and says why.')],
            [__('Can I use my own logo and colours?'), __('Yes. An administrator sets your organization\'s logo and colours in the portal, and your logo is printed on the invoices you issue.')],
            [__('Which languages does it work in?'), __('Arabic and English, in the portal and on every invoice. Each person can switch at any time.')],
        ] as [$question, $answer])
            <x-ui.disclosure :summary="$question" :open="$loop->first">{{ $answer }}</x-ui.disclosure>
        @endforeach
    </div>
</section>
