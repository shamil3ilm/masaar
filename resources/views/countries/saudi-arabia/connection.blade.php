@extends('layouts.portal')

@section('title', __('ZATCA'))

@php
    $environment = (string) config('fatoora.environment');
    $signIn = match ($environment) {
        'simulation' => __('Sign in to the Fatoora portal\'s Simulation environment, choose to onboard a new solution unit, and generate one OTP.'),
        // The sandbox is said as production is: this page is the owner's,
        // and the test code is a developer's to know.
        default => __('Sign in to the Fatoora portal, choose to onboard a new solution unit, and generate one OTP.'),
    };
    $documents = [
        'standard_invoice' => __('Standard invoice'),
        'standard_credit_note' => __('Standard credit note'),
        'standard_debit_note' => __('Standard debit note'),
        'simplified_invoice' => __('Simplified invoice'),
        'simplified_credit_note' => __('Simplified credit note'),
        'simplified_debit_note' => __('Simplified debit note'),
    ];
    $done = fn (string $step) => ($status['steps'][$step] ?? 'pending') === 'completed';
@endphp

@section('content')
<x-ui.page-header :title="__('Connect to ZATCA')" :description="__('Three steps, once. Afterwards every invoice you issue is signed with your own certificate and cleared or reported to ZATCA.')" />

@if ($status['is_onboarded'])
    <x-ui.alert tone="success" class="mb-6" :title="__('Connected.')">{{ __(':organization is onboarded with ZATCA (:environment).', ['organization' => $organization->name, 'environment' => $environment]) }}</x-ui.alert>
@elseif ($blocked)
    <x-ui.alert tone="warning" class="mb-6">{{ $blocked }}</x-ui.alert>
@elseif (! $status['profile_complete'])
    <x-ui.alert tone="warning" class="mb-6">
        {{ __('Your company details are incomplete. ZATCA needs the VAT number and the full national address -') }}
        <a href="{{ route('portal.company.edit') }}" class="underline">{{ __('complete them first') }}</a>.
    </x-ui.alert>
@endif

@php($disabled = $status['is_onboarded'] || $blocked || ! $status['profile_complete'])

<div class="max-w-2xl space-y-6">
    <section class="rounded-card bg-surface border border-line shadow-card">
        <div class="flex items-center justify-between border-b p-4">
            <h3 class="font-semibold text-fg">{{ __('1. Compliance certificate') }}</h3>
            <x-ui.badge :state="$done('step_1') ? 'completed' : 'pending'" />
        </div>
        <form method="POST" action="{{ route('portal.zatca.ccsid') }}" class="space-y-4 p-4">
            @csrf
            <p class="text-sm text-muted">
                {{ $signIn }}
                {{ __('It is valid for an hour - enter it here straight away.') }}
            </p>
            <x-ui.field :label="__('OTP')">
                <x-ui.input type="text" name="otp" inputmode="numeric" maxlength="6" autocomplete="one-time-code" dir="ltr" :disabled="$disabled" />
            </x-ui.field>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.field :label="__('Device name')">
                    <x-ui.input type="text" name="common_name" maxlength="64" value="{{ old('common_name', $defaultDevice) }}" :disabled="$disabled" />
                </x-ui.field>
                <x-ui.field :label="__('Branch or unit')">
                    <x-ui.input type="text" name="organization_unit" maxlength="64" value="{{ old('organization_unit', 'Main Branch') }}" :disabled="$disabled" />
                    <span class="mt-1 block text-xs font-normal text-muted">{{ __('For a VAT group, the member\'s 10-digit TIN.') }}</span>
                </x-ui.field>
                <x-ui.field :label="__('Business category')">
                    <x-ui.input type="text" name="industry" maxlength="64" value="{{ old('industry', 'General') }}" :disabled="$disabled" />
                </x-ui.field>
            </div>
            <x-ui.button variant="primary" :disabled="$disabled">{{ __('Request the certificate') }}</x-ui.button>
        </form>
    </section>

    <section class="rounded-card bg-surface border border-line shadow-card">
        <div class="flex items-center justify-between border-b p-4">
            <h3 class="font-semibold text-fg">{{ __('2. Compliance checks') }}</h3>
            <x-ui.badge :state="$done('step_3') ? 'completed' : ($status['has_ccsid'] ? 'in_progress' : 'pending')" />
        </div>
        <form method="POST" action="{{ route('portal.zatca.check') }}" class="space-y-4 p-4">
            @csrf
            <p class="text-sm text-muted">
                {{ __('Six sample documents - standard and simplified invoice, credit note and debit note - built from your company\'s details and sent to ZATCA to check before it issues the production certificate.') }}
            </p>
            <x-ui.button variant="primary" :disabled="$disabled || ! $status['has_ccsid']">{{ __('Run the checks') }}</x-ui.button>
        </form>

        @if (session('checks'))
            <div class="border-t p-4">
                <x-ui.table :primary="[0, 1]">
                    <x-slot:head>
                        <x-ui.th>{{ __('Document') }}</x-ui.th>
                        <x-ui.th>{{ __('Result') }}</x-ui.th>
                        <x-ui.th>{{ __('ZATCA said') }}</x-ui.th>
                    </x-slot:head>
                    @foreach (session('checks') as $type => $check)
                        <tr>
                            <td class="px-4 py-2 text-sm text-fg">{{ $documents[$type] ?? str_replace('_', ' ', (string) $type) }}</td>
                            <td class="px-4 py-2 text-sm"><x-ui.badge :state="($check['passed'] ?? false) ? 'passed' : 'failed'" /></td>
                            <td class="px-4 py-2 text-xs text-muted" dir="ltr">
                                {{ implode('; ', array_map(fn ($m) => is_array($m) ? ($m['message'] ?? json_encode($m)) : (string) $m, [...($check['errors'] ?? []), ...($check['warnings'] ?? [])])) ?: '-' }}
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </div>
        @endif
    </section>

    <section class="rounded-card bg-surface border border-line shadow-card">
        <div class="flex items-center justify-between border-b p-4">
            <h3 class="font-semibold text-fg">{{ __('3. Production certificate') }}</h3>
            <x-ui.badge :state="$done('step_3') ? 'completed' : 'pending'" />
        </div>
        <form method="POST" action="{{ route('portal.zatca.production') }}" class="space-y-4 p-4">
            @csrf
            <p class="text-sm text-muted">{{ __('Once the checks pass. This completes the connection.') }}</p>
            <x-ui.button variant="primary" :disabled="$disabled || ! $status['has_ccsid']">{{ __('Get the production certificate') }}</x-ui.button>
        </form>
    </section>
</div>
@endsection
