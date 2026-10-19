@extends('layouts.portal')

@section('title', __('Certificates'))

{{--
    Each of the organization's tills - its EGS units, head office's first -
    with where it stands, and every ZATCA certificate it has held, newest
    first, with how many documents still wait to be filed under each
    (PortalZatcaController::certificates). A document is filed with the
    certificate that signed it, so a superseded certificate with documents
    waiting is still in use.

    @param list<array> $devices    CertificateVersions::forOrganization
    @param bool        $renewable  head office holds a production certificate (CertificateRenewal);
                                   each till is connected or renewed from its own page
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
        <div class="flex flex-wrap items-center gap-2 px-5 py-3 text-sm">
            <x-ui.badge :state="$device['status']" :tone="in_array($device['status'], ['retiring', 'retired', 'lost'], true) ? 'neutral' : null" :label="$device['status_label']" />
            <x-ui.badge :tone="$device['ready'] ? 'success' : 'warning'" :label="$device['ready'] ? __('Ready to issue') : __('Not connected to ZATCA')" />
            @if ($device['expires'])
                <span class="text-muted">{{ __('Certificate valid until') }} <x-ui.date :value="$device['expires']" /></span>
            @endif
            @if (! $device['head_office'] && in_array($device['status'], ['active', 'retiring'], true))
                <div class="ms-auto flex flex-wrap gap-2">
                    @if ($device['issues'])
                        {{-- Each till's own certificate: connected, then renewed, on its own page. --}}
                        <x-ui.button variant="secondary" size="sm" :href="route('portal.zatca.tills.certificate', $device['device'])">
                            {{ collect($device['versions'])->contains('status', 'active') ? __('Renew') : __('Connect') }}
                        </x-ui.button>
                    @endif
                    <form method="POST" action="{{ route('portal.zatca.tills.retire', $device['device']) }}"
                          data-confirm-tone="danger" data-confirm-action="{{ __('Retire') }}"
                          data-confirm="{{ __('Retire :till?', ['till' => $device['name']]) }}"
                          data-confirm-detail="{{ __('It issues no new invoices. Once everything it signed is filed with ZATCA it is retired for good and cannot be brought back.') }}">
                        @csrf
                        <x-ui.button variant="danger" size="sm">{{ $device['status'] === 'retiring' ? __('Finish retiring') : __('Retire') }}</x-ui.button>
                    </form>
                </div>
            @endif
        </div>
        @if ($device['versions'] === [])
            <p class="border-t border-line px-5 py-3 text-sm text-muted">{{ __('This till holds no certificate yet. Connect it to ZATCA with its own one-time code.') }}</p>
        @else
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
                    <td class="px-4 py-3 text-sm text-muted"><x-ui.date :value="$version['valid_from'] ?? null" /></td>
                    <td class="px-4 py-3 text-sm text-muted"><x-ui.date :value="$version['valid_to'] ?? null" /></td>
                    <td class="px-4 py-3 text-xs text-muted" dir="ltr">
                        <div>{{ $version['issuer'] ?? '-' }}</div>
                        <div>{{ $version['serial'] ?? '-' }}</div>
                    </td>
                    <td class="px-4 py-3 text-sm {{ $version['waiting'] > 0 ? 'font-medium text-warning' : 'text-muted' }}">{{ $version['waiting'] }}</td>
                </tr>
            @endforeach
        </x-ui.table>
        @endif
    </x-ui.section>
@empty
    <x-ui.card>
        <p class="text-sm text-muted">{{ __('No device holds a ZATCA production certificate yet. Connect to ZATCA to obtain one.') }}</p>
    </x-ui.card>
@endforelse
@endsection
