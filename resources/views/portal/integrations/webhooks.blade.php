@extends('layouts.portal')

@section('title', __('Webhooks'))

@section('content')
<x-ui.page-header :title="__('Integrations')"
                  :description="__('Your systems are told what happened to your invoices as it happens: one HTTPS endpoint per receiver, sent only the events it needs.')" />

@include('portal.integrations.partials.nav')

<div class="max-w-5xl space-y-6">
    <x-ui.card :title="__('Webhooks')" flush>
        <x-ui.table :primary="[0, 2]">
            <x-slot:head>
                <x-ui.th>{{ __('Endpoint') }}</x-ui.th>
                <x-ui.th>{{ __('Events') }}</x-ui.th>
                <x-ui.th>{{ __('Status') }}</x-ui.th>
                <x-ui.th>{{ __('Last delivered') }}</x-ui.th>
                <x-ui.th></x-ui.th>
            </x-slot:head>
            @forelse ($endpoints as $endpoint)
                <tr class="align-top" data-webhook="{{ $endpoint->id }}">
                    <td class="px-4 py-3 font-mono text-xs text-fg break-all" dir="ltr">{{ $endpoint->url }}</td>
                    <td class="px-4 py-3 text-xs text-muted">{{ in_array('*', (array) $endpoint->events, true) ? __('Every event') : count((array) $endpoint->events) }}</td>
                    <td class="px-4 py-3 text-sm">
                        @if ($endpoint->is_active)
                            <x-ui.badge state="active" />
                        @else
                            <x-ui.badge tone="warning" :label="__('Switched off')" />
                        @endif
                        @if ($endpoint->failure_count > 0)
                            <p class="mt-1 text-xs text-muted">{{ __(':count failed deliveries in a row', ['count' => $endpoint->failure_count]) }}</p>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs text-muted">
                        <x-ui.date :value="$endpoint->last_triggered_at" time :zone="\App\Support\CountryClock::timeZone($organization->country)" :empty="__('Not yet')" />
                    </td>
                    <td class="px-4 py-3 text-end">
                        <x-ui.button variant="secondary" size="sm" :href="route('portal.integrations.webhooks.show', $endpoint->id)">{{ __('Open') }}</x-ui.button>
                    </td>
                </tr>
            @empty
                <x-ui.empty :columns="5">{{ __('No webhooks yet.') }}</x-ui.empty>
            @endforelse
        </x-ui.table>
    </x-ui.card>

    @if ($refusal !== null)
        <x-ui.alert tone="info" :title="__('You cannot add a webhook now')">{{ $refusal }}</x-ui.alert>
    @else
        <form method="POST" action="{{ route('portal.integrations.webhooks.store') }}" class="rounded-card border border-line bg-surface shadow-card" data-webhook-form>
            @csrf
            <div class="border-b border-line p-5">
                <h2 class="text-base font-semibold text-fg">{{ __('Add a webhook') }}</h2>
                <p class="mt-0.5 text-sm text-subtle">{{ __('The signing secret is shown once, on the next page.') }}</p>
            </div>
            <div class="space-y-4 p-5">
                <x-ui.field :label="__('Endpoint URL')" :hint="__('HTTPS, reachable from the internet.')" error="url">
                    <x-ui.input type="url" name="url" maxlength="500" required dir="ltr" placeholder="https://" value="{{ old('url') }}" />
                </x-ui.field>
                @include('portal.integrations.partials.events', ['chosen' => (array) old('events', ['invoice.issued', 'invoice.cleared', 'invoice.reported', 'invoice.rejected'])])
            </div>
            <div class="border-t border-line p-5">
                <x-ui.button variant="primary">{{ __('Add the webhook') }}</x-ui.button>
            </div>
        </form>
    @endif
</div>
@endsection
