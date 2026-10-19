{{--
    How to reach the operator: a short form, and the address and phone from
    config/company.php.

    The form is laid out for the inbox the website work will add. Until a
    route named contact.store exists it does not pretend to send: its button
    gives way to writing to the address, so nothing typed into it is lost in
    silence. The topic comes from ?topic= (the "Ask us" links above set it;
    resources/js/ui.js sets it in place when script runs).
--}}
@php
    $topics = [
        'general' => __('General'),
        'country' => __('New country'),
        'feature' => __('Feature request'),
        'customization' => __('Customization'),
        'pricing' => __('Pricing'),
        // The key stays 'engineer': links already carry ?topic=engineer.
        'engineer' => __('Set it up with us'),
    ];
    $topic = array_key_exists((string) request('topic'), $topics) ? (string) request('topic') : 'general';
    $sends = \Illuminate\Support\Facades\Route::has('contact.store');
    // Where an admin has pointed contact elsewhere (Settings: site.contact_url).
    $elsewhere = $publicSite->contactUrl();
@endphp

<section id="contact" class="relative scroll-mt-20 pb-24 pt-14 sm:pt-16" aria-labelledby="contact-title">
    <span class="route-stop border-ink-900 bg-highlight" aria-hidden="true"></span>
    <div class="grid gap-10 lg:grid-cols-[1fr_1.3fr]">
        <div>
            <x-ui.section-heading id="contact-title" :eyebrow="__('Contact us')"
                :title="__('Tell us about your business.')"
                :pair="__('Tell us about your business.', [], $pair)" />
            <p class="mt-4 max-w-lg text-muted">{{ __('Ask about a plan, a country, a feature or a customization, before or after you sign up.') }}</p>

            @if (config('company.email') || config('company.phone'))
                <dl class="mt-8 space-y-4">
                    @if (config('company.email'))
                        <div>
                            <dt class="text-sm text-subtle">{{ __('Email') }}</dt>
                            <dd class="mt-1"><a href="mailto:{{ config('company.email') }}" class="text-lg font-semibold text-accent underline-offset-4 hover:underline" dir="ltr">{{ config('company.email') }}</a></dd>
                        </div>
                    @endif
                    @if (config('company.phone'))
                        <div>
                            <dt class="text-sm text-subtle">{{ __('Phone') }}</dt>
                            <dd class="mt-1 text-lg font-semibold" dir="ltr">{{ config('company.phone') }}</dd>
                        </div>
                    @endif
                </dl>
            @endif
        </div>

        {{-- Not a form until it can send: a form with nowhere to go would
             reload the page with what was typed in its address. --}}
        <{{ $sends ? 'form' : 'div' }} @if ($sends) method="POST" action="{{ route('contact.store') }}" @endif
              class="grid gap-4 rounded-card border border-line bg-surface p-6 sm:grid-cols-2 sm:p-8" aria-labelledby="contact-title">
            @if ($sends)
                @csrf
            @endif
            <x-ui.field :label="__('Your name')">
                <x-ui.input name="name" autocomplete="name" maxlength="255" />
            </x-ui.field>
            <x-ui.field :label="__('Work email')">
                <x-ui.input type="email" name="email" autocomplete="email" maxlength="255" />
            </x-ui.field>
            <x-ui.field :label="__('Company')">
                <x-ui.input name="company" autocomplete="organization" maxlength="255" />
            </x-ui.field>
            <x-ui.field :label="__('Topic')">
                <x-ui.select name="topic" id="contact-topic">
                    @foreach ($topics as $value => $label)
                        <option value="{{ $value }}" @selected($topic === $value)>{{ $label }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.field :label="__('How can we help?')" class="sm:col-span-2">
                <x-ui.textarea name="message" rows="4" maxlength="5000" />
            </x-ui.field>
            <div class="sm:col-span-2">
                @if ($elsewhere)
                    <x-ui.button size="lg" :href="$elsewhere" rel="noopener">{{ __('Write to us') }}</x-ui.button>
                @elseif ($sends)
                    <x-ui.button size="lg">{{ __('Send message') }}</x-ui.button>
                @elseif (config('company.email'))
                    <x-ui.button size="lg" :href="'mailto:'.config('company.email')">{{ __('Write to us') }}</x-ui.button>
                    <p class="mt-2 text-xs text-subtle">{{ __('Sending from this form is not open yet. Write to us at the address beside it.') }}</p>
                @else
                    @auth
                        <x-ui.button size="lg" :href="route('portal.support.index')">{{ __('Open Support') }}</x-ui.button>
                    @else
                        <p class="text-sm text-muted">{{ __('Sending from this form is not open yet. Signed in, you can ask us anything from Support in the portal.') }}</p>
                    @endauth
                @endif
            </div>
        </{{ $sends ? 'form' : 'div' }}>
    </div>
</section>
