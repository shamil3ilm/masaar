@extends('layouts.portal')

@section('title', __('API keys'))

@php
    $zone = \App\Support\CountryClock::timeZone($organization->country);
    $environments = ['sandbox' => __('Sandbox'), 'production' => __('Production')];
    $made = session('api_key_made');
@endphp

@section('content')
<x-ui.page-header :title="__('Integrations')"
                  :description="__('Connect your ERP, store or own software to :app. Each key acts for :organization under its plan, with only the permissions you give it.', ['organization' => $organization->name])" />

@include('portal.integrations.partials.nav')

<div class="max-w-5xl space-y-6">
    {{-- What an API call names as org_id (the developers page says to look here). --}}
    <p class="text-sm text-muted">{{ __('Organization ID') }}: <code class="font-mono text-fg break-all">{{ $organization->id }}</code></p>
    {{-- The one moment the secret exists in plain text: the key keeps only its hash. --}}
    @if (is_array($made))
        <x-ui.alert tone="warning" :title="__('Copy the secret now')" data-api-key-secret>
            <p>{{ __('It is stored only as a hash and cannot be shown again. If it is lost, revoke the key and make a new one.') }}</p>
            <dl class="mt-3 space-y-2 text-sm">
                <div>
                    <dt class="font-medium">{{ __('API key') }} ({{ $made['name'] }})</dt>
                    <dd><code class="block break-all rounded bg-surface px-3 py-2 font-mono text-fg" dir="ltr">{{ $made['key'] }}</code></dd>
                </div>
                <div>
                    <dt class="font-medium">{{ __('API secret') }}</dt>
                    <dd><code class="block break-all rounded bg-surface px-3 py-2 font-mono text-fg" dir="ltr">{{ $made['secret'] }}</code></dd>
                </div>
            </dl>
            <p class="mt-3 text-sm">{{ __('Send both in the X-API-Key and X-API-Secret headers of every request.') }}</p>
        </x-ui.alert>
    @endif

    @if ($allowance !== null)
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-ui.stat :label="__('Invoices on your plan this month')"
                       :value="$allowance['invoices_limit'] === null ? $allowance['invoices'] : __(':used of :limit', ['used' => $allowance['invoices'], 'limit' => $allowance['invoices_limit']])" />
            <x-ui.stat :label="__('API calls today')"
                       :value="$allowance['calls_limit'] === null ? $allowance['calls_today'] : __(':used of :limit', ['used' => $allowance['calls_today'], 'limit' => $allowance['calls_limit']])" />
        </div>
        <p class="text-sm text-muted">{{ __('Every key draws on the same allowances: your plan\'s, as set for your organization.') }}</p>
    @endif

    {{-- How this month's invoices ended (InvoiceReport::outcomesSince): a rejected one is not filed. --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-ui.stat data-outcome="accepted" :label="__('Accepted this month')" :value="$outcomes['accepted']" :tone="$outcomes['accepted'] > 0 ? 'success' : null" />
        <x-ui.stat data-outcome="rejected" :label="__('Rejected this month')" :value="$outcomes['rejected']" :tone="$outcomes['rejected'] > 0 ? 'danger' : null" />
        <x-ui.stat data-outcome="waiting" :label="__('Not filed yet')" :value="$outcomes['waiting']" :tone="$outcomes['waiting'] > 0 ? 'warning' : null" />
    </div>

    <x-ui.card :title="__('API keys')" flush>
        <x-ui.table :primary="[0, 5]">
            <x-slot:head>
                <x-ui.th>{{ __('Name') }}</x-ui.th>
                <x-ui.th>{{ __('Key') }}</x-ui.th>
                <x-ui.th>{{ __('May') }}</x-ui.th>
                <x-ui.th>{{ __('Limits') }}</x-ui.th>
                <x-ui.th>{{ __('Use') }}</x-ui.th>
                <x-ui.th>{{ __('Status') }}</x-ui.th>
                <x-ui.th></x-ui.th>
            </x-slot:head>
            @forelse ($keys as $row)
                @php($key = $row['key'])
                <tr class="align-top" data-api-key="{{ $key->id }}">
                    <td class="px-4 py-3 text-sm">
                        <div class="font-medium text-fg">{{ $key->name }}</div>
                        <x-ui.badge :tone="$key->environment->value === 'production' ? 'info' : 'neutral'" :label="$environments[$key->environment->value]" />
                    </td>
                    <td class="px-4 py-3 font-mono text-xs text-muted break-all" dir="ltr">{{ $key->api_key }}</td>
                    <td class="px-4 py-3 text-xs text-muted">
                        <ul class="space-y-0.5">
                            @foreach ($key->scopes as $scope)
                                <li>{{ \App\Domains\Licensing\Enums\ApiScope::tryFrom($scope)?->label() ?? $scope }}</li>
                            @endforeach
                        </ul>
                    </td>
                    <td class="px-4 py-3 text-xs text-muted">
                        <div>{{ __('Expires') }}: <x-ui.date :value="$key->expires_at" :empty="__('Never')" /></div>
                        <div>{{ __('From') }}:
                            @if ($key->allowed_ips)
                                <span dir="ltr" class="font-mono">{{ implode(', ', $key->allowed_ips) }}</span>
                            @else
                                {{ __('Any address') }}
                            @endif
                        </div>
                    </td>
                    <td class="px-4 py-3 text-xs text-muted">
                        <div>{{ __('Today: :requests requests, :errors errors', ['requests' => $row['today'], 'errors' => $row['today_errors']]) }}</div>
                        <div>{{ __('This month: :requests requests, :errors errors', ['requests' => $row['month'], 'errors' => $row['month_errors']]) }}</div>
                        <div>{{ __('Last used') }}: <x-ui.date :value="$key->last_used_at" time :zone="$zone" :empty="__('Not yet')" /></div>
                    </td>
                    <td class="px-4 py-3 text-sm">
                        @if ($key->isActive())
                            <x-ui.badge state="active" />
                        @elseif ($row['approval'] !== null && ! $row['approval']->isPending())
                            <x-ui.badge :tone="$row['approval']->tone()" :label="$row['approval']->statusLabel()" />
                            @if ($row['approval']->decision_note)
                                <p class="mt-1 text-xs text-muted">{{ $row['approval']->decision_note }}</p>
                            @endif
                        @else
                            <x-ui.badge tone="warning" :label="__('Waiting for approval')" />
                        @endif
                    </td>
                    <td class="px-4 py-3 text-end">
                        <form method="POST" action="{{ route('portal.integrations.keys.revoke', $key->id) }}"
                              data-confirm-tone="danger" data-confirm-action="{{ __('Revoke') }}"
                              data-confirm="{{ __('Revoke the API key :name?', ['name' => $key->name]) }}"
                              data-confirm-detail="{{ __('Every system using it is refused from its next request. This cannot be undone: make a new key instead.') }}">
                            @csrf
                            <x-ui.button variant="danger" size="sm">{{ __('Revoke') }}</x-ui.button>
                        </form>
                    </td>
                </tr>
            @empty
                <x-ui.empty :columns="7">{{ __('No API keys yet.') }}</x-ui.empty>
            @endforelse
        </x-ui.table>
    </x-ui.card>

    @if ($refusal !== null)
        <x-ui.alert tone="info" :title="__('You cannot make an API key now')">{{ $refusal }}</x-ui.alert>
    @else
        <form method="POST" action="{{ route('portal.integrations.keys.store') }}" class="rounded-card border border-line bg-surface shadow-card" data-api-key-form>
            @csrf
            <div class="border-b border-line p-5">
                <h2 class="text-base font-semibold text-fg">{{ __('Make an API key') }}</h2>
                <p class="mt-0.5 text-sm text-subtle">{{ __('A sandbox key is for trying the API: it never issues or files anything with the live tax authority. A production key files for real, and may wait for a platform administrator\'s approval.') }}</p>
            </div>
            <div class="grid grid-cols-1 gap-4 p-5 sm:grid-cols-2">
                <x-ui.field :label="__('Name')" :hint="__('What uses it, so you know which to revoke: the ERP, the online store.')" error="name">
                    <x-ui.input type="text" name="name" maxlength="100" required value="{{ old('name') }}" />
                </x-ui.field>
                <fieldset>
                    <legend class="text-sm font-medium text-fg">{{ __('Environment') }}</legend>
                    <div class="mt-1 flex flex-wrap gap-x-6">
                        @foreach ($environments as $value => $label)
                            <x-ui.radio name="environment" value="{{ $value }}" :checked="old('environment', 'sandbox') === $value">{{ $label }}</x-ui.radio>
                        @endforeach
                    </div>
                </fieldset>
            </div>
            <fieldset class="border-t border-line p-5">
                <legend class="text-sm font-medium text-fg">{{ __('What it may do') }}</legend>
                <p class="text-xs text-subtle">{{ __('Only what your plan includes is offered. Give it no more than it needs.') }}</p>
                <div class="mt-2 grid grid-cols-1 gap-x-6 sm:grid-cols-2">
                    @foreach ($scopes as $scope)
                        <x-ui.checkbox name="scopes[]" value="{{ $scope }}" :checked="in_array($scope, (array) old('scopes', []), true)">{{ \App\Domains\Licensing\Enums\ApiScope::from($scope)->label() }}</x-ui.checkbox>
                    @endforeach
                </div>
                @error('scopes')
                    <p class="mt-1 text-sm text-danger">{{ $message }}</p>
                @enderror
            </fieldset>
            <x-ui.section :title="__('Limits')" :description="__('Optional. A key that stops on a date, or works only from your servers\' addresses.')" collapse class="mx-5 mb-5">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field :label="__('Expires on')" optional error="expires_at">
                        <x-ui.date-input name="expires_at" value="{{ old('expires_at') }}" min="{{ now()->addDay()->toDateString() }}" />
                    </x-ui.field>
                    <x-ui.field :label="__('Allowed addresses')" :hint="__('IP addresses or CIDR ranges, one a line. Empty: any address.')" optional error="allowed_ips">
                        <x-ui.textarea name="allowed_ips" rows="3" dir="ltr">{{ old('allowed_ips') }}</x-ui.textarea>
                    </x-ui.field>
                </div>
            </x-ui.section>
            <div class="border-t border-line p-5">
                <x-ui.field :label="__('Why it is needed')" :hint="__('Asked for when a production key waits for approval: the administrator deciding reads it.')" optional error="approval_reason">
                    <x-ui.textarea name="approval_reason" rows="2" maxlength="2000">{{ old('approval_reason') }}</x-ui.textarea>
                </x-ui.field>
            </div>
            <div class="border-t border-line p-5">
                <x-ui.button variant="primary">{{ __('Make the key') }}</x-ui.button>
            </div>
        </form>
    @endif
</div>
@endsection
