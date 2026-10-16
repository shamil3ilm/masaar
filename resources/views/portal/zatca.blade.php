@extends('layouts.portal')

@section('title', __('ZATCA'))

@php
    $environment = (string) config('fatoora.environment');
    $signIn = match ($environment) {
        'simulation' => __('Sign in to the Fatoora portal\'s Simulation environment, choose to onboard a new solution unit, and generate one OTP.'),
        'sandbox' => __('Sign in to the developer portal (any OTP the sandbox accepts, such as 123345), choose to onboard a new solution unit, and generate one OTP.'),
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
<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ __('Connect to ZATCA') }}</h2>
    <p class="text-gray-500 dark:text-gray-400">
        {{ __('Three steps, once. Afterwards every invoice you issue is signed with your own certificate and cleared or reported to ZATCA.') }}
    </p>
</div>

@if ($status['is_onboarded'])
    <div class="mb-6 rounded border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-950/40 px-4 py-3 text-sm text-green-800 dark:text-green-200">
        <strong>{{ __('Connected.') }}</strong> {{ __(':organization is onboarded with ZATCA (:environment).', ['organization' => $organization->name, 'environment' => $environment]) }}
    </div>
@elseif ($blocked)
    <div class="mb-6 rounded border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/40 px-4 py-3 text-sm text-amber-800 dark:text-amber-200">
        {{ $blocked }}
    </div>
@elseif (! $status['profile_complete'])
    <div class="mb-6 rounded border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/40 px-4 py-3 text-sm text-amber-800 dark:text-amber-200">
        {{ __('Your company details are incomplete. ZATCA needs the VAT number and the full national address -') }}
        <a href="{{ route('portal.company.edit') }}" class="underline">{{ __('complete them first') }}</a>.
    </div>
@endif

@php($disabled = $status['is_onboarded'] || $blocked || ! $status['profile_complete'])

<div class="max-w-2xl space-y-6">
    <section class="rounded-lg bg-white dark:bg-gray-900 shadow">
        <div class="flex items-center justify-between border-b p-4">
            <h3 class="font-semibold text-gray-800 dark:text-gray-100">{{ __('1. Compliance certificate') }}</h3>
            <x-ui.badge :state="$done('step_1') ? 'completed' : 'pending'" />
        </div>
        <form method="POST" action="{{ route('portal.zatca.ccsid') }}" class="space-y-4 p-4">
            @csrf
            <p class="text-sm text-gray-600 dark:text-gray-300">
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
                    <span class="mt-1 block text-xs font-normal text-gray-500 dark:text-gray-400">{{ __('For a VAT group, the member\'s 10-digit TIN.') }}</span>
                </x-ui.field>
                <x-ui.field :label="__('Business category')">
                    <x-ui.input type="text" name="industry" maxlength="64" value="{{ old('industry', 'General') }}" :disabled="$disabled" />
                </x-ui.field>
            </div>
            <x-ui.button variant="primary" :disabled="$disabled">{{ __('Request the certificate') }}</x-ui.button>
        </form>
    </section>

    <section class="rounded-lg bg-white dark:bg-gray-900 shadow">
        <div class="flex items-center justify-between border-b p-4">
            <h3 class="font-semibold text-gray-800 dark:text-gray-100">{{ __('2. Compliance checks') }}</h3>
            <x-ui.badge :state="$done('step_3') ? 'completed' : ($status['has_ccsid'] ? 'in_progress' : 'pending')" />
        </div>
        <form method="POST" action="{{ route('portal.zatca.check') }}" class="space-y-4 p-4">
            @csrf
            <p class="text-sm text-gray-600 dark:text-gray-300">
                {{ __('Six sample documents - standard and simplified invoice, credit note and debit note - built from your company\'s details and sent to ZATCA to check before it issues the production certificate.') }}
            </p>
            <x-ui.button variant="primary" :disabled="$disabled || ! $status['has_ccsid']">{{ __('Run the checks') }}</x-ui.button>
        </form>

        @if (session('checks'))
            <div class="border-t p-4">
                <x-ui.table>
                    <x-slot:head>
                        <x-ui.th>{{ __('Document') }}</x-ui.th>
                        <x-ui.th>{{ __('Result') }}</x-ui.th>
                        <x-ui.th>{{ __('ZATCA said') }}</x-ui.th>
                    </x-slot:head>
                    @foreach (session('checks') as $type => $check)
                        <tr>
                            <td class="px-4 py-2 text-sm text-gray-800 dark:text-gray-100">{{ $documents[$type] ?? str_replace('_', ' ', (string) $type) }}</td>
                            <td class="px-4 py-2 text-sm"><x-ui.badge :state="($check['passed'] ?? false) ? 'passed' : 'failed'" /></td>
                            <td class="px-4 py-2 text-xs text-gray-600 dark:text-gray-300" dir="ltr">
                                {{ implode('; ', array_map(fn ($m) => is_array($m) ? ($m['message'] ?? json_encode($m)) : (string) $m, [...($check['errors'] ?? []), ...($check['warnings'] ?? [])])) ?: '-' }}
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </div>
        @endif
    </section>

    <section class="rounded-lg bg-white dark:bg-gray-900 shadow">
        <div class="flex items-center justify-between border-b p-4">
            <h3 class="font-semibold text-gray-800 dark:text-gray-100">{{ __('3. Production certificate') }}</h3>
            <x-ui.badge :state="$done('step_3') ? 'completed' : 'pending'" />
        </div>
        <form method="POST" action="{{ route('portal.zatca.production') }}" class="space-y-4 p-4">
            @csrf
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('Once the checks pass. This completes the connection.') }}</p>
            <x-ui.button variant="primary" :disabled="$disabled || ! $status['has_ccsid']">{{ __('Get the production certificate') }}</x-ui.button>
        </form>
    </section>
</div>
@endsection
