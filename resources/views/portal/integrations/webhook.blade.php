@extends('layouts.portal')

@section('title', __('Webhook'))

@php
    $zone = \App\Support\CountryClock::timeZone($organization->country);
@endphp

@section('content')
<x-ui.page-header :title="__('Webhook')">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Webhooks'), 'href' => route('portal.integrations.webhooks.index')], ['label' => __('Webhook')]]" />
    </x-slot:breadcrumb>
    <x-slot:description><span class="font-mono break-all" dir="ltr">{{ $endpoint->url }}</span></x-slot:description>
    <x-slot:actions>
        <form method="POST" action="{{ route('portal.integrations.webhooks.test', $endpoint->id) }}">
            @csrf
            <x-ui.button variant="secondary">{{ __('Send a test event') }}</x-ui.button>
        </form>
    </x-slot:actions>
</x-ui.page-header>

<div class="max-w-5xl space-y-6">
    {{-- The one moment the signing secret is shown: on adding, and on rotating. --}}
    @if (session('webhook_secret'))
        <x-ui.alert tone="warning" :title="__('Copy the signing secret now')" data-webhook-secret>
            <p>{{ __('Your receiver checks each delivery\'s X-Webhook-Signature with it. It is not shown again; rotate it to get a new one.') }}</p>
            <code class="mt-3 block break-all rounded bg-surface px-3 py-2 font-mono text-sm text-fg" dir="ltr">{{ session('webhook_secret') }}</code>
        </x-ui.alert>
    @endif

    <form method="POST" action="{{ route('portal.integrations.webhooks.update', $endpoint->id) }}" class="rounded-card border border-line bg-surface shadow-card">
        @csrf
        <div class="space-y-4 p-5">
            <x-ui.field :label="__('Endpoint URL')" :hint="__('HTTPS, reachable from the internet.')" error="url">
                <x-ui.input type="url" name="url" maxlength="500" required dir="ltr" value="{{ old('url', $endpoint->url) }}" />
            </x-ui.field>
            <x-ui.switch name="is_active" value="1" off="0" :checked="(bool) old('is_active', $endpoint->is_active)"
                         :description="__('Switched off, it is sent nothing. One that keeps failing for a day is switched off by itself.')">{{ __('Receives events') }}</x-ui.switch>
            @include('portal.integrations.partials.events', ['chosen' => (array) old('events', $endpoint->events)])
        </div>
        <div class="border-t border-line p-5">
            <x-ui.button variant="primary">{{ __('Save') }}</x-ui.button>
        </div>
    </form>

    <x-ui.card :title="__('Recent deliveries')" :description="__('Each event once, with every attempt at it: an unanswered delivery is retried for about forty minutes. Personal data in what was sent and answered is not shown.')" flush>
        <x-ui.table :primary="[0, 3]">
            <x-slot:head>
                <x-ui.th>{{ __('Event') }}</x-ui.th>
                <x-ui.th>{{ __('When') }}</x-ui.th>
                <x-ui.th>{{ __('Attempts') }}</x-ui.th>
                <x-ui.th>{{ __('Answer') }}</x-ui.th>
                <x-ui.th>{{ __('Details') }}</x-ui.th>
            </x-slot:head>
            @forelse ($deliveries as $delivery)
                <tr class="align-top" data-delivery="{{ $delivery['delivery_id'] }}">
                    <td class="px-4 py-3 text-sm">
                        <div class="text-fg">{{ $events[$delivery['event']] ?? \App\Domains\Webhook\Services\WebhookEvents::label($delivery['event']) }}</div>
                        <div class="font-mono text-xs text-subtle" dir="ltr">{{ $delivery['event'] }}</div>
                    </td>
                    <td class="px-4 py-3 text-xs text-muted"><x-ui.date :value="$delivery['last_attempt']" time seconds :zone="$zone" /></td>
                    <td class="px-4 py-3 text-sm text-muted tabular-nums">{{ $delivery['attempts'] }}</td>
                    <td class="px-4 py-3 text-sm">
                        @if ($delivery['delivered'])
                            <x-ui.badge tone="success" :label="__('Delivered (:status)', ['status' => $delivery['status']])" />
                        @elseif ($delivery['status'] === 0)
                            <x-ui.badge tone="warning" :label="__('No answer')" />
                        @else
                            <x-ui.badge tone="danger" :label="__('Refused (:status)', ['status' => $delivery['status']])" />
                        @endif
                        <p class="mt-1 text-xs text-muted tabular-nums">{{ __(':ms ms', ['ms' => $delivery['duration_ms']]) }}</p>
                    </td>
                    <td class="px-4 py-3 text-xs">
                        <x-ui.disclosure :summary="__('What was sent')" class="border-b-0 py-0">
                            <pre class="mt-2 max-w-md overflow-x-auto rounded bg-sunken p-3 font-mono text-xs text-fg" dir="ltr">{{ $delivery['payload'] }}</pre>
                            @if ($delivery['response'] !== '')
                                <p class="mt-2 font-medium text-fg">{{ __('Answer') }}</p>
                                <pre class="mt-1 max-w-md overflow-x-auto whitespace-pre-wrap rounded bg-sunken p-3 font-mono text-xs text-fg" dir="ltr">{{ $delivery['response'] }}</pre>
                            @endif
                        </x-ui.disclosure>
                    </td>
                </tr>
            @empty
                <x-ui.empty :columns="5">{{ __('Nothing delivered yet. Send a test event to try the endpoint.') }}</x-ui.empty>
            @endforelse
        </x-ui.table>
    </x-ui.card>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <form method="POST" action="{{ route('portal.integrations.webhooks.rotate', $endpoint->id) }}" class="rounded-card border border-line bg-surface p-5 shadow-card"
              data-confirm-tone="danger" data-confirm-action="{{ __('Rotate') }}"
              data-confirm="{{ __('Rotate the signing secret?') }}"
              data-confirm-detail="{{ __('For a day each delivery is signed with the old secret and the new one, so your receiver accepts them while it changes over.') }}">
            @csrf
            <h2 class="text-base font-semibold text-fg">{{ __('Signing secret') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('Shown once when it is made. Rotate it if it may have been seen by someone else.') }}</p>
            <div class="mt-3"><x-ui.checkbox name="revoke_previous" value="1">{{ __('End the old secret now: it may have been seen by someone else') }}</x-ui.checkbox></div>
            <div class="mt-3"><x-ui.button variant="secondary">{{ __('Rotate the secret') }}</x-ui.button></div>
        </form>
        <form method="POST" action="{{ route('portal.integrations.webhooks.destroy', $endpoint->id) }}" class="rounded-card border border-line bg-surface p-5 shadow-card"
              data-confirm-tone="danger" data-confirm-action="{{ __('Remove') }}"
              data-confirm="{{ __('Remove this webhook?') }}"
              data-confirm-detail="{{ __('It is sent nothing from now on.') }}">
            @csrf
            <h2 class="text-base font-semibold text-fg">{{ __('Remove') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('Nothing more is sent to this endpoint.') }}</p>
            <div class="mt-3"><x-ui.button variant="danger">{{ __('Remove the webhook') }}</x-ui.button></div>
        </form>
    </div>
</div>
@endsection
