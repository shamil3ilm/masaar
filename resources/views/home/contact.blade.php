{{--
    How to reach the operator, from config/company.php.

    A way to write rather than a form: there is nothing yet that receives a
    message from someone without an account, and a form that posts nowhere
    is worse than none. Without an address configured, it points at signing
    up or signing in instead of at nothing.
--}}
<section id="contact" class="relative scroll-mt-20 pb-24 pt-14 sm:pt-16" aria-labelledby="contact-title">
    <span class="route-stop border-ink-900 bg-highlight" aria-hidden="true"></span>
    <div class="grid gap-10 lg:grid-cols-2">
        <div>
            <x-ui.section-heading id="contact-title" :eyebrow="__('Contact us')"
                :title="__('Tell us about your business.')"
                :pair="__('Tell us about your business.', [], $pair)" />
            <p class="mt-4 max-w-lg text-muted">{{ __('Ask about a plan, a country or connecting your ERP, before or after you sign up.') }}</p>
        </div>

        <div class="rounded-card border border-line bg-surface p-6 sm:p-8">
            @if (config('company.email') || config('company.phone'))
                <dl class="space-y-5">
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
                @if (config('company.email'))
                    <x-ui.button class="mt-6" :href="'mailto:'.config('company.email')">{{ __('Write to us') }}</x-ui.button>
                @endif
            @else
                <p class="text-muted">{{ __('Signed in, you can ask us anything from Support in the portal.') }}</p>
                <div class="mt-6 flex flex-wrap gap-3">
                    @auth
                        <x-ui.button :href="route('portal.support.index')">{{ __('Open Support') }}</x-ui.button>
                    @else
                        @if (config('signup.enabled'))
                            <x-ui.button :href="route('register')">{{ __('Create your account') }}</x-ui.button>
                        @endif
                        <x-ui.button variant="secondary" :href="route('login')">{{ __('Sign in') }}</x-ui.button>
                    @endauth
                </div>
            @endif
        </div>
    </div>
</section>
