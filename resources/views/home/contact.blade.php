{{--
    How to reach the operator: a short form, and the address and phone to
    write or call instead.

    The form posts to contact.store, which keeps the message for the
    console's Website inbox and emails whoever answers them. Sent, the page
    comes back here with a thank-you in both languages instead of the form
    (post, redirect, get); refused, with what was typed still in it and why.

    Two fields are not for people: a honeypot every script fills in and
    nobody sees, and when the page was opened, so a form sent in less time
    than anyone types is known for what it is (ContactGuard).

    Where an admin has pointed contact elsewhere (Settings: site.contact_url)
    there is no form: the button goes there. The address shown is the
    setting's, else config/company.php's (PublicSite::contactEmail()).

    The topic comes from ?topic= (the "Ask us" links above set it;
    resources/js/ui.js sets it in place when script runs), or what was chosen
    before a refusal.

    The heading and introduction are Admin › Website's; the form is the
    code's.
--}}
@php
    $topics = \App\Domains\Platform\Website\ContactTopic::options();
    $asked = (string) old('topic', request('topic'));
    $topic = array_key_exists($asked, $topics) ? $asked : 'general';
    $elsewhere = $publicSite->contactUrl();
    $email = $publicSite->contactEmail();
    $sent = (bool) session('contact_sent');
@endphp

<section id="contact" class="relative scroll-mt-20 pb-24 pt-14 sm:pt-16" aria-labelledby="contact-title">
    <span class="route-stop border-ink-900 bg-highlight" aria-hidden="true"></span>
    <div class="grid gap-10 lg:grid-cols-[1fr_1.3fr]">
        <div>
            <x-ui.section-heading id="contact-title" :eyebrow="$section->text('eyebrow')"
                :title="$section->text('title')" :pair="$section->pair('title')" />
            <p class="mt-4 max-w-lg text-muted">{{ $section->html('intro') }}</p>

            @if ($email || config('company.phone'))
                <dl class="mt-8 space-y-4">
                    @if ($email)
                        <div>
                            <dt class="text-sm text-subtle">{{ __('Rather write? Email us') }}</dt>
                            <dd class="mt-1"><a href="mailto:{{ $email }}" class="text-lg font-semibold text-accent underline-offset-4 hover:underline" dir="ltr">{{ $email }}</a></dd>
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

        @if ($elsewhere)
            <div class="flex flex-col items-start justify-center gap-3 rounded-card border border-line bg-surface p-6 sm:p-8">
                <p class="text-muted">{{ __('We answer every message. Write to us and tell us what you need.') }}</p>
                <x-ui.button size="lg" :href="$elsewhere" rel="noopener">{{ __('Write to us') }}</x-ui.button>
            </div>
        @elseif ($sent)
            <div class="rounded-card border border-line bg-surface p-6 sm:p-8" data-contact-sent>
                <x-ui.alert tone="success" :title="__('Thank you. Your message has arrived.')">
                    <p>{{ __('We will reply by email, to the address you gave.') }}</p>
                    <p class="mt-3" lang="{{ $pair }}" dir="{{ $pair === 'ar' ? 'rtl' : 'ltr' }}">
                        <span class="font-semibold">{{ __('Thank you. Your message has arrived.', [], $pair) }}</span>
                        {{ __('We will reply by email, to the address you gave.', [], $pair) }}
                    </p>
                </x-ui.alert>
                <x-ui.button variant="secondary" class="mt-5" :href="url('/').'#contact'">{{ __('Send another message') }}</x-ui.button>
            </div>
        @else
            <form method="POST" action="{{ route('contact.store') }}"
                  class="grid gap-4 rounded-card border border-line bg-surface p-6 sm:grid-cols-2 sm:p-8" aria-labelledby="contact-title">
                @csrf
                <input type="hidden" name="{{ \App\Domains\Platform\Website\ContactGuard::STARTED }}" value="{{ app(\App\Domains\Platform\Website\ContactGuard::class)->startedToken() }}">

                @error(\App\Domains\Platform\Website\ContactGuard::ERROR_KEY)
                    <x-ui.alert tone="danger" class="sm:col-span-2">{{ $message }}</x-ui.alert>
                @enderror

                <x-ui.field :label="__('Your name')" error="name">
                    <x-ui.input name="name" autocomplete="name" maxlength="120" required value="{{ old('name') }}" />
                </x-ui.field>
                <x-ui.field :label="__('Work email')" error="email">
                    <x-ui.input type="email" name="email" autocomplete="email" maxlength="255" required dir="ltr" value="{{ old('email') }}" />
                </x-ui.field>
                <x-ui.field :label="__('Company')" error="company" optional>
                    <x-ui.input name="company" autocomplete="organization" maxlength="160" value="{{ old('company') }}" />
                </x-ui.field>
                <x-ui.field :label="__('Country')" error="country" optional>
                    <x-ui.input name="country" autocomplete="country-name" maxlength="100" value="{{ old('country') }}" />
                </x-ui.field>
                <x-ui.field :label="__('Topic')" error="topic" class="sm:col-span-2">
                    <x-ui.select name="topic" id="contact-topic">
                        @foreach ($topics as $value => $label)
                            <option value="{{ $value }}" @selected($topic === $value)>{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field :label="__('How can we help?')" error="message" class="sm:col-span-2">
                    <x-ui.textarea name="message" rows="4" maxlength="5000" required>{{ old('message') }}</x-ui.textarea>
                </x-ui.field>

                {{-- Not for people: hidden from sight, from screen readers
                     and from the tab order. Anything typed here is a script's. --}}
                <div class="hidden" aria-hidden="true">
                    <x-ui.field :label="__('Leave this empty')">
                        <x-ui.input name="{{ \App\Domains\Platform\Website\ContactGuard::HONEYPOT }}" tabindex="-1" autocomplete="off" />
                    </x-ui.field>
                </div>

                <div class="sm:col-span-2">
                    <x-ui.button size="lg">{{ __('Send message') }}</x-ui.button>
                    <p class="mt-2 text-xs text-subtle">{{ __('We use what you send only to answer you. Your network address is kept for :days days, to keep out spam.', ['days' => config('website.contact.keep_details_days')]) }}</p>
                </div>
            </form>
        @endif
    </div>
</section>
