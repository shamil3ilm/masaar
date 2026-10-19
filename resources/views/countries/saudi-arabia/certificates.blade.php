@extends('layouts.portal')

@section('title', __('Certificates'))

{{--
    Every ZATCA certificate each of the organization's devices has held,
    newest first, and how many documents still wait to be filed under each
    (PortalZatcaController::certificates). A document is filed with the
    certificate that signed it, so a superseded certificate with documents
    waiting is still in use.

    @param list<array> $devices    CertificateVersions::forOrganization
    @param bool        $renewable  head office holds a production certificate (CertificateRenewal)
--}}

@section('content')
<x-ui.page-header :title="__('Certificates')"
                  :description="__('Each device signs with its active certificate. A renewal supersedes the old one, which still files what it signed; a revoked one files nothing.')">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('ZATCA'), 'href' => route('portal.zatca.show')], ['label' => __('Certificates')]]" />
    </x-slot:breadcrumb>
    @if ($renewable)
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('portal.zatca.renew')">{{ __('Renew the certificate') }}</x-ui.button>
        </x-slot:actions>
    @endif
</x-ui.page-header>

@forelse ($devices as $device)
    <x-ui.section :title="$device['name']" flush class="mb-6">
        <x-ui.table :primary="[0, 1, 5]">
            <x-slot:head>
                <x-ui.th>{{ __('Version') }}</x-ui.th>
                <x-ui.th>{{ __('Status') }}</x-ui.th>
                <x-ui.th>{{ __('Valid from') }}</x-ui.th>
                <x-ui.th>{{ __('Valid until') }}</x-ui.th>
                <x-ui.th>{{ __('Issuer and serial') }}</x-ui.th>
                <x-ui.th>{{ __('Waiting to file') }}</x-ui.th>
            </x-slot:head>
            @foreach ($device['versions'] as $version)
                <tr>
                    <td class="px-4 py-3 text-sm font-medium text-fg">{{ $version['version'] }}</td>
                    <td class="px-4 py-3 text-sm">
                        <x-ui.badge :state="$version['status']" :label="match ($version['status']) {
                            'active' => __('Active'),
                            'superseded' => __('Superseded'),
                            'revoked' => __('Revoked'),
                            default => $version['status'],
                        }" />
                    </td>
                    <td class="px-4 py-3 text-sm text-muted" dir="ltr">{{ $version['valid_from'] ?? '-' }}</td>
                    <td class="px-4 py-3 text-sm text-muted" dir="ltr">{{ $version['valid_to'] ?? '-' }}</td>
                    <td class="px-4 py-3 text-xs text-muted" dir="ltr">
                        <div>{{ $version['issuer'] ?? '-' }}</div>
                        <div>{{ $version['serial'] ?? '-' }}</div>
                    </td>
                    <td class="px-4 py-3 text-sm {{ $version['waiting'] > 0 ? 'font-medium text-warning' : 'text-muted' }}">{{ $version['waiting'] }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.section>
@empty
    <x-ui.card>
        <p class="text-sm text-muted">{{ __('No device holds a ZATCA production certificate yet. Connect to ZATCA to obtain one.') }}</p>
    </x-ui.card>
@endforelse
@endsection
