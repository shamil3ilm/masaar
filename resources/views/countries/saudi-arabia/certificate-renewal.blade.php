@extends('layouts.portal')

@section('title', __('Renew the certificate'))

{{--
    Renewing the organization's ZATCA certificate (PortalCertificateRenewalController),
    reached from the Certificates panel. What the current certificate signed
    and ZATCA does not hold yet comes first (RenewalGuard): renewing revokes
    that certificate, so with any listed the renewal needs a reason. Then a
    new OTP runs the three onboarding steps; if the checks fail after the OTP
    is spent, the renewal is finished here without another one.

    @param bool        $renewable      a production certificate is held
    @param bool        $pending        the OTP was spent and the renewal not finished
    @param array|null  $current        the active head-office version (CertificateVersions)
    @param Collection  $waiting        RenewalGuard::waiting
    @param string|null $blocked        why the company may not file now
    @param string      $defaultDevice
    @param int         $minReason      RenewalGuard::MIN_REASON
--}}

@php
    $reasons = [
        'offline_queue' => __('Queued offline'),
        'overdue' => __('Report overdue'),
        'awaiting_filing' => __('Waiting for ZATCA'),
    ];
    $documents = [
        'standard_invoice' => __('Standard invoice'),
        'standard_credit_note' => __('Standard credit note'),
        'standard_debit_note' => __('Standard debit note'),
        'simplified_invoice' => __('Simplified invoice'),
        'simplified_credit_note' => __('Simplified credit note'),
        'simplified_debit_note' => __('Simplified debit note'),
    ];
    $disabled = ! $renewable || $blocked;
    $listed = 50;
@endphp

@section('content')
<x-ui.page-header :title="__('Renew the certificate')"
                  :description="__('A new OTP gets a new certificate for head office. New invoices are signed with it; the old one is superseded and still files what it signed.')">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('ZATCA'), 'href' => route('portal.zatca.show')], ['label' => __('Certificates'), 'href' => route('portal.zatca.certificates')], ['label' => __('Renew')]]" />
    </x-slot:breadcrumb>
</x-ui.page-header>

<div class="max-w-2xl space-y-6">
    @if (! $renewable)
        <x-ui.alert>
            {{ __('There is no production certificate to renew. Connect to ZATCA first.') }}
            <a href="{{ route('portal.zatca.show') }}" class="underline">{{ __('Connect to ZATCA') }}</a>
        </x-ui.alert>
    @elseif ($blocked)
        <x-ui.alert tone="warning">{{ $blocked }}</x-ui.alert>
    @endif

    @if ($current)
        <x-ui.card>
            <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-muted">{{ __('Current certificate') }}</dt>
                    <dd class="mt-1 font-medium text-fg">{{ __('Version :version', ['version' => $current['version']]) }}</dd>
                </div>
                <div>
                    <dt class="text-muted">{{ __('Valid until') }}</dt>
                    <dd class="mt-1 font-medium text-fg" dir="ltr">{{ $current['valid_to'] ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-muted">{{ __('Waiting to file') }}</dt>
                    <dd class="mt-1 font-medium {{ $waiting->isNotEmpty() ? 'text-warning' : 'text-fg' }}">{{ $waiting->count() }}</dd>
                </div>
            </dl>
        </x-ui.card>
    @endif

    @if ($waiting->isNotEmpty())
        <x-ui.section :title="__('Not filed with ZATCA yet')"
                      :description="__('Renewing revokes the current certificate, and ZATCA may then refuse these. File them first, or give a reason to renew anyway; the reason is recorded.')"
                      flush>
            <x-ui.table :primary="[0, 1]">
                <x-slot:head>
                    <x-ui.th>{{ __('Invoice') }}</x-ui.th>
                    <x-ui.th>{{ __('Why it waits') }}</x-ui.th>
                </x-slot:head>
                @foreach ($waiting->take($listed) as $document)
                    <tr>
                        <td class="px-4 py-3 text-sm font-medium text-fg" dir="ltr">{{ $document['invoice_number'] }}</td>
                        <td class="px-4 py-3 text-sm text-muted">{{ $reasons[$document['reason']] ?? $document['reason'] }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
            @if ($waiting->count() > $listed)
                <p class="border-t border-line px-4 py-3 text-sm text-muted">{{ __('And :count more.', ['count' => $waiting->count() - $listed]) }}</p>
            @endif
        </x-ui.section>
    @endif

    @if ($pending)
        <x-ui.section :title="__('Finish the renewal')"
                      :description="__('The OTP is spent and the new compliance certificate is held. Run the compliance checks again and get the production certificate; your current certificate signs until then.')">
            <form method="POST" action="{{ route('portal.zatca.renew.finish') }}" class="space-y-4">
                @csrf
                @include('countries.saudi-arabia.partials.renewal-override')
                <x-ui.button variant="primary" :disabled="$disabled">{{ __('Finish the renewal') }}</x-ui.button>
            </form>
        </x-ui.section>
    @else
        <x-ui.section :title="__('New OTP')">
            <form method="POST" action="{{ route('portal.zatca.renew.store') }}" class="space-y-4">
                @csrf
                <p class="text-sm text-muted">
                    {{ __('Sign in to the Fatoora portal, choose to renew the solution unit\'s certificate, and generate one OTP. It is valid for an hour - enter it here straight away.') }}
                </p>
                <x-ui.field :label="__('OTP')" error="otp">
                    <x-ui.input type="text" name="otp" inputmode="numeric" maxlength="6" autocomplete="one-time-code" dir="ltr" required :disabled="$disabled" />
                </x-ui.field>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field :label="__('Device name')" error="common_name">
                        <x-ui.input type="text" name="common_name" maxlength="64" value="{{ old('common_name', $defaultDevice) }}" required :disabled="$disabled" />
                    </x-ui.field>
                    <x-ui.field :label="__('Branch or unit')" error="organization_unit" optional>
                        <x-ui.input type="text" name="organization_unit" maxlength="64" value="{{ old('organization_unit', 'Main Branch') }}" :disabled="$disabled" />
                    </x-ui.field>
                    <x-ui.field :label="__('Business category')" error="industry" optional>
                        <x-ui.input type="text" name="industry" maxlength="64" value="{{ old('industry', 'General') }}" :disabled="$disabled" />
                    </x-ui.field>
                </div>
                @include('countries.saudi-arabia.partials.renewal-override')
                <x-ui.button variant="primary" :disabled="$disabled">{{ __('Renew the certificate') }}</x-ui.button>
            </form>
        </x-ui.section>
    @endif

    @if (session('checks'))
        <x-ui.section :title="__('Compliance checks')" flush>
            <x-ui.table :primary="[0, 1]">
                <x-slot:head>
                    <x-ui.th>{{ __('Document') }}</x-ui.th>
                    <x-ui.th>{{ __('Result') }}</x-ui.th>
                    <x-ui.th>{{ __('ZATCA said') }}</x-ui.th>
                </x-slot:head>
                @foreach (session('checks') as $type => $check)
                    <tr>
                        <td class="px-4 py-3 text-sm text-fg">{{ $documents[$type] ?? str_replace('_', ' ', (string) $type) }}</td>
                        <td class="px-4 py-3 text-sm"><x-ui.badge :state="($check['passed'] ?? false) ? 'passed' : 'failed'" /></td>
                        <td class="px-4 py-3 text-xs text-muted" dir="ltr">
                            {{ implode('; ', array_map(fn ($m) => is_array($m) ? ($m['message'] ?? json_encode($m)) : (string) $m, [...($check['errors'] ?? []), ...($check['warnings'] ?? [])])) ?: '-' }}
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.section>
    @endif
</div>
@endsection
