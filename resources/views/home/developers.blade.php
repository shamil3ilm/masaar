{{--
    /developers: where an integrator starts. Getting a key, the quick start
    in four languages (QuickStart, held to the API by DocumentedExamplesTest),
    the webhook events, and the API reference rendered from the OpenAPI
    description (ApiReference). Everything is served from here: no script,
    style or font from anywhere else, as the content security policy wants.
--}}
@extends('layouts.public')

@section('title', __('Developers'))

@section('content')
<article class="px-4 py-12 sm:px-6 sm:py-16">
    <div class="mx-auto max-w-4xl space-y-12">
        <header class="space-y-4">
            <p class="text-eyebrow font-semibold uppercase text-accent">{{ __('Developers') }}</p>
            <h1 class="text-title font-semibold text-fg">{{ __('Connect your systems to :app') }}</h1>
            <p class="text-lg text-muted">{{ __('Create invoices from your ERP, online store or own software, have them issued and filed, and hear back the moment the tax authority answers.') }}</p>
        </header>

        <section aria-labelledby="start-title" class="space-y-4">
            <h2 id="start-title" class="text-xl font-semibold text-fg">{{ __('Getting started') }}</h2>
            <ol class="list-decimal space-y-3 ps-5 text-muted">
                <li>{{ __('Make an API key. Your organization\'s administrator does it in the portal, under Integrations. Start with a sandbox key: it never files anything with the live tax authority.') }}
                    <a href="{{ route('portal.integrations.index') }}" class="text-accent underline underline-offset-4">{{ __('Open Integrations') }}</a></li>
                <li>{{ __('Send the key and its secret in the X-API-Key and X-API-Secret headers of every request, over HTTPS. Never put them in the address.') }}</li>
                <li>{{ __('Submit an invoice, follow its status, and add a webhook to be told when it is answered.') }}</li>
                <li>{{ __('Put your organization\'s ID in org_id: every submission names the organization it is for.') }} {{ __('Your organization ID is on the portal\'s Integrations page, beside your keys.') }}</li>
            </ol>
            <div class="rounded-tile border border-line bg-surface p-4 text-sm">
                <p class="font-medium text-fg">{{ __('Base address') }}</p>
                <code class="mt-1 block break-all font-mono text-fg" dir="ltr">{{ $base }}/api/v1</code>
            </div>
        </section>

        <section aria-labelledby="submit-title" class="space-y-4">
            <h2 id="submit-title" class="text-xl font-semibold text-fg">{{ __('Submit an invoice') }}</h2>
            <p class="text-muted">{{ __('One call creates the invoice, issues it and files it. Send it again with the same Idempotency-Key and you get the first answer back, never a second invoice.') }}</p>
            @foreach ($submissions as $code => $submission)
                <div class="space-y-2" data-quick-start="{{ $code }}">
                    @if (count($submissions) > 1)
                        <h3 class="text-base font-semibold text-fg">{{ __($submission['name']) }}</h3>
                    @endif
                    @include('home.partials.code-tabs', ['id' => 'submit-'.strtolower($code), 'samples' => $submission['samples']])
                </div>
            @endforeach
        </section>

        <section aria-labelledby="status-title" class="space-y-4">
            <h2 id="status-title" class="text-xl font-semibold text-fg">{{ __('Follow its status') }}</h2>
            <p class="text-muted">{{ __('Where the invoice stands - issued, filed, accepted or refused - with the authority\'s answer. A webhook tells you without asking.') }}</p>
            @include('home.partials.code-tabs', ['id' => 'status', 'samples' => $status])
        </section>

        <section aria-labelledby="webhooks-title" class="space-y-4">
            <h2 id="webhooks-title" class="text-xl font-semibold text-fg">{{ __('Webhooks') }}</h2>
            <p class="text-muted">{{ __('Add an endpoint in the portal, under Integrations, and choose its events. Each delivery is a signed POST: check X-Webhook-Signature against the raw body before acting on it, and answer with any 2xx. A delivery that is not answered is retried for about forty minutes under the same X-Webhook-ID.') }}</p>
            @include('home.partials.code-tabs', ['id' => 'webhook', 'samples' => $webhook])
            <x-ui.disclosure :summary="__('The events')">
                <dl class="mt-3 grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                    @foreach ($events as $event => $label)
                        <div>
                            <dt class="font-mono text-xs text-fg" dir="ltr">{{ $event }}</dt>
                            <dd class="text-muted">{{ $label }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-ui.disclosure>
        </section>

        <section aria-labelledby="reference-title" class="space-y-6">
            <div>
                <h2 id="reference-title" class="text-xl font-semibold text-fg">{{ __('API reference') }}</h2>
                <p class="mt-1 text-muted">{{ __('Every endpoint, generated from the routes themselves. Version :version.', ['version' => $version]) }}</p>
            </div>

            <div class="rounded-tile border border-line bg-surface p-4 text-sm">
                <p class="font-medium text-fg">{{ __('Authentication') }}</p>
                <dl class="mt-2 space-y-1">
                    @foreach ($schemes as $name => $scheme)
                        <div class="flex flex-wrap gap-x-3">
                            <dt class="font-mono text-xs text-fg" dir="ltr">{{ $name }}</dt>
                            <dd class="font-mono text-xs text-muted" dir="ltr">{{ ($scheme['in'] ?? null) === 'header' ? __('header :name', ['name' => $scheme['name'] ?? '']) : trim(($scheme['scheme'] ?? '').' '.($scheme['bearerFormat'] ?? '')) }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            <h3 class="text-lg font-semibold text-fg">{{ __('Partner API') }}</h3>
            <p class="text-sm text-muted">{{ __('Called with an API key and secret. Each endpoint says the permission it needs.') }}</p>
            @foreach ($groups['partner'] as $tag => $operations)
                <div class="space-y-3">
                    <h4 class="text-sm font-semibold uppercase tracking-wide text-subtle" dir="ltr">{{ $tag }}</h4>
                    @foreach ($operations as $operation)
                        @include('home.partials.operation', ['operation' => $operation])
                    @endforeach
                </div>
            @endforeach

            <h3 class="text-lg font-semibold text-fg">{{ __('Other endpoints') }}</h3>
            <p class="text-sm text-muted">{{ __('For a person signed in to the app, for the platform\'s operators, and for monitoring.') }}</p>
            @foreach ($groups['other'] as $tag => $operations)
                <x-ui.disclosure :summary="$tag.' ('.count($operations).')'">
                    <div class="mt-3 space-y-3">
                        @foreach ($operations as $operation)
                            @include('home.partials.operation', ['operation' => $operation])
                        @endforeach
                    </div>
                </x-ui.disclosure>
            @endforeach
        </section>
    </div>
</article>
@endsection
