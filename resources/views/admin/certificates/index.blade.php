@extends('layouts.admin')

@section('title', 'Device Certificates')

{{--
    Every production certificate each device has held, newest first per
    device, and marking one revoked (AdminCertificateController). A POST
    behind data-confirm, with a reason: it stops the device signing.
--}}
@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-semibold tracking-tight text-fg">Device certificates</h1>
    <p class="mt-1 max-w-3xl text-sm text-muted">
        <code class="text-xs">fatoora:check-revocation</code> reads the CA's revocation lists daily and records what they revoke.
        Mark one here when ZATCA confirms a revocation, or a key is known to be compromised, before the list says so.
    </p>
</div>

<div class="bg-surface rounded-card border border-line shadow-card overflow-hidden">
    <x-ui.table :primary="[0, 2, 3]">
        <x-slot:head>
            <x-ui.th>Organization</x-ui.th>
            <x-ui.th>Device</x-ui.th>
            <x-ui.th>Version</x-ui.th>
            <x-ui.th>Status</x-ui.th>
            <x-ui.th>Serial</x-ui.th>
            <x-ui.th>Valid until</x-ui.th>
            <x-ui.th>Action</x-ui.th>
        </x-slot:head>

        @forelse($versions as $version)
        <tr class="hover:bg-sunken">
            <td class="px-6 py-4 font-medium text-fg">{{ $version->organization_name }}</td>
            <td class="px-6 py-4 text-sm text-muted">{{ $version->device_id === $version->org_id ? 'Head office' : Str::limit($version->device_id, 8, '') }}</td>
            <td class="px-6 py-4 text-sm text-muted">{{ $version->version }}</td>
            <td class="px-6 py-4"><x-ui.badge :state="$version->status" /></td>
            <td class="px-6 py-4 text-xs text-muted">{{ $version->serial_number ?? '-' }}</td>
            <td class="px-6 py-4 text-sm text-muted">{{ $version->valid_to?->format('Y-m-d') ?? '-' }}</td>
            <td class="px-6 py-4">
                @if($version->status !== 'revoked')
                @admincan('platform.configure')
                <form method="POST" action="{{ route('admin.certificates.revoke', $version->id) }}" class="flex flex-wrap items-end gap-2"
                      data-confirm-tone="danger" data-confirm-action="Mark revoked"
                      data-confirm="Mark version {{ $version->version }} revoked? Nothing more is filed with it, and if the device signs with it, it stops.">
                    @csrf
                    <x-ui.input name="reason" type="text" required minlength="5" maxlength="500" placeholder="Reason" aria-label="Reason" />
                    <x-ui.button variant="danger">Mark revoked</x-ui.button>
                </form>
                @endadmincan
                @else
                <span class="text-sm text-muted">-</span>
                @endif
            </td>
        </tr>
        @empty
        <x-ui.empty :columns="7">No device holds a production certificate yet.</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

<div class="mt-4">{{ $versions->links() }}</div>
@endsection
