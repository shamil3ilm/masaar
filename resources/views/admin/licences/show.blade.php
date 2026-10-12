@extends('layouts.admin')

@section('title', 'Licence - Masaar Admin')

@section('content')
@php
    // getLicenseDetails() hands over the model through toArray(), so everything
    // here is a plain scalar: 'status' and 'tier' are the backing strings rather
    // than the enums, and the dates are ISO strings.
    $row = $licence['license'];
    $usage = $licence['usage'];

    // tryFrom, not from: a status the enum does not know would otherwise take
    // the whole page down instead of showing the row that needs attention.
    $status = \App\Domains\Licensing\Enums\LicenseStatus::tryFrom((string) $row['status']);
    $tier = \App\Domains\Licensing\Enums\LicenseTier::tryFrom((string) $row['tier']);

    $when = fn (?string $value): string => $value
        ? \Illuminate\Support\Carbon::parse($value)->toDayDateTimeString()
        : '-';

    // Only the transitions that mean something for this status. canReactivate()
    // is the enum's own answer - Suspended or Expired, never Revoked.
    $canSuspend = $status === \App\Domains\Licensing\Enums\LicenseStatus::Active;
    $canReactivate = $status?->canReactivate() ?? false;
    $canRevoke = $status !== \App\Domains\Licensing\Enums\LicenseStatus::Revoked;
@endphp

{{-- Only ever populated by store(), and only for the one request after it. --}}
@include('admin.licences.partials.secret-once')

<div class="mb-6 flex flex-wrap items-start justify-between gap-4">
    <div>
        <a href="{{ route('admin.licences.index') }}" class="text-sm text-teal-700 hover:text-teal-900">&larr; All licences</a>
        <h2 class="mt-1 text-2xl font-bold text-gray-800">{{ $row['organization_name'] }}</h2>
        <p class="text-gray-500">{{ $row['contact_email'] }}</p>
    </div>
    <div class="flex items-center gap-3">
        <span class="rounded-full px-3 py-1 text-xs font-medium
            @switch($status)
                @case(\App\Domains\Licensing\Enums\LicenseStatus::Active) bg-green-100 text-green-800 @break
                @case(\App\Domains\Licensing\Enums\LicenseStatus::Suspended) bg-yellow-100 text-yellow-800 @break
                @case(\App\Domains\Licensing\Enums\LicenseStatus::PendingActivation) bg-blue-100 text-blue-800 @break
                @default bg-red-100 text-red-800
            @endswitch">
            {{ $status?->getLabel() ?? $row['status'] }}
        </span>
        <span class="text-xs text-gray-500">
            {{ $licence['is_valid'] ? 'Serving API traffic' : 'Not serving API traffic' }}
        </span>
    </div>
</div>

<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <div class="rounded-lg bg-white shadow">
            <h3 class="border-b px-6 py-4 text-sm font-semibold uppercase text-gray-500">Partner</h3>
            <dl class="grid gap-4 px-6 py-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs uppercase text-gray-500">Organization</dt>
                    <dd class="text-sm text-gray-900">{{ $row['organization_name'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-gray-500">VAT number</dt>
                    <dd class="text-sm text-gray-900">{{ $row['organization_vat'] ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-gray-500">Contact email</dt>
                    <dd class="text-sm text-gray-900">{{ $row['contact_email'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-gray-500">Contact phone</dt>
                    <dd class="text-sm text-gray-900">{{ $row['contact_phone'] ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-gray-500">Tenant</dt>
                    {{-- ValidateLicense scopes every partner call to this org, so a
                         licence without one authenticates and then reaches nothing. --}}
                    <dd class="font-mono text-xs break-all text-gray-900">
                        {{ $row['org_id'] ?? 'Not linked to an organization' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-gray-500">Environment</dt>
                    <dd class="text-sm text-gray-900">{{ $row['environment'] }}</dd>
                </div>
            </dl>
        </div>

        <div class="rounded-lg bg-white shadow">
            <h3 class="border-b px-6 py-4 text-sm font-semibold uppercase text-gray-500">Credentials</h3>
            <dl class="space-y-4 px-6 py-4">
                <div>
                    <dt class="text-xs uppercase text-gray-500">API key</dt>
                    <dd class="font-mono text-sm break-all text-gray-900">{{ $row['api_key'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-gray-500">API secret</dt>
                    {{-- Hashed on save and hidden on the model, so it cannot be read
                         back. Replacing it is regenerateSecret(), which has no screen. --}}
                    <dd class="text-sm text-gray-500">Stored as a hash and cannot be shown again</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-gray-500">Scopes</dt>
                    <dd class="mt-1 flex flex-wrap gap-2">
                        @forelse(($row['scopes'] ?? []) as $scope)
                            <span class="rounded bg-gray-100 px-2 py-1 font-mono text-xs text-gray-700">{{ $scope }}</span>
                        @empty
                            <span class="text-sm text-gray-500">No scopes - this key can call nothing</span>
                        @endforelse
                    </dd>
                </div>
            </dl>
        </div>

        <div class="rounded-lg bg-white shadow">
            <h3 class="border-b px-6 py-4 text-sm font-semibold uppercase text-gray-500">
                Quotas and usage ({{ $usage['month'] }})
            </h3>
            <dl class="grid gap-4 px-6 py-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs uppercase text-gray-500">Tier</dt>
                    <dd class="text-sm text-gray-900">{{ $tier?->getDisplayName() ?? $row['tier'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-gray-500">Invoices this month</dt>
                    <dd class="text-sm text-gray-900">
                        {{ number_format((int) $usage['totals']['invoices_submitted']) }}
                        of {{ number_format((int) $usage['limits']['invoices_per_month']) }}
                        <span class="text-xs text-gray-500">({{ $usage['utilization']['invoices_percent'] }}%)</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-gray-500">Calls per day</dt>
                    <dd class="text-sm text-gray-900">{{ number_format((int) $row['calls_per_day']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-gray-500">Calls per minute</dt>
                    <dd class="text-sm text-gray-900">{{ number_format((int) $row['calls_per_min']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-gray-500">Organizations allowed</dt>
                    <dd class="text-sm text-gray-900">{{ number_format((int) $row['max_organizations']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-gray-500">Clearance success rate</dt>
                    <dd class="text-sm text-gray-900">{{ $usage['utilization']['success_rate'] }}%</dd>
                </div>
            </dl>
        </div>

        <div class="rounded-lg bg-white shadow">
            <h3 class="border-b px-6 py-4 text-sm font-semibold uppercase text-gray-500">Dates</h3>
            <dl class="grid gap-4 px-6 py-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs uppercase text-gray-500">Issued</dt>
                    <dd class="text-sm text-gray-900">{{ $when($row['created_at'] ?? null) }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-gray-500">Activated</dt>
                    <dd class="text-sm text-gray-900">{{ $when($row['activated_at'] ?? null) }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-gray-500">Expires</dt>
                    <dd class="text-sm text-gray-900">
                        {{ $row['expires_at'] ? $when($row['expires_at']) : 'Never' }}
                        @if($licence['days_until_expiry'] !== null)
                            <span class="text-xs text-gray-500">
                                ({{ (int) floor((float) $licence['days_until_expiry']) }} days)
                            </span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-gray-500">Suspended</dt>
                    <dd class="text-sm text-gray-900">{{ $when($row['suspended_at'] ?? null) }}</dd>
                </div>
            </dl>
            @if($row['suspension_reason'])
                <div class="border-t px-6 py-4">
                    <dt class="text-xs uppercase text-gray-500">Reason on record</dt>
                    <dd class="text-sm text-gray-900">{{ $row['suspension_reason'] }}</dd>
                </div>
            @endif
        </div>
    </div>

    <div class="space-y-6">
        <div class="rounded-lg bg-white shadow">
            <h3 class="border-b px-6 py-4 text-sm font-semibold uppercase text-gray-500">Lifecycle</h3>
            <div class="space-y-6 px-6 py-4">
                @if($canSuspend)
                    <form method="POST" action="{{ route('admin.licences.suspend', $row['id']) }}"
                          onsubmit="return confirm('Suspend this licence? The partner stops being able to call the API the moment you confirm.')">
                        @csrf
                        <label for="suspend-reason" class="block text-xs font-medium uppercase text-gray-500">Reason</label>
                        <input id="suspend-reason" name="reason" type="text" maxlength="255" required
                               placeholder="Why the partner is being cut off"
                               class="mt-1 w-full rounded-md border-gray-300 text-sm">
                        <button type="submit"
                                class="mt-2 w-full rounded-md bg-yellow-600 px-4 py-2 text-sm text-white hover:bg-yellow-700">
                            Suspend licence
                        </button>
                        <p class="mt-1 text-xs text-gray-500">Reversible. Invoices already submitted are untouched.</p>
                    </form>
                @endif

                @if($canReactivate)
                    <form method="POST" action="{{ route('admin.licences.reactivate', $row['id']) }}">
                        @csrf
                        <button type="submit"
                                class="w-full rounded-md bg-green-600 px-4 py-2 text-sm text-white hover:bg-green-700">
                            Reactivate licence
                        </button>
                        <p class="mt-1 text-xs text-gray-500">Clears the suspension and lets the partner call again.</p>
                    </form>
                @endif

                @if($canRevoke)
                    <form method="POST" action="{{ route('admin.licences.revoke', $row['id']) }}"
                          onsubmit="return confirm('Revoke this licence? This is permanent and the partner integration stops working now.')">
                        @csrf
                        <label for="revoke-reason" class="block text-xs font-medium uppercase text-gray-500">Reason</label>
                        <input id="revoke-reason" name="reason" type="text" maxlength="255" required
                               placeholder="Why the licence is being withdrawn"
                               class="mt-1 w-full rounded-md border-gray-300 text-sm">
                        <button type="submit"
                                class="mt-2 w-full rounded-md bg-red-600 px-4 py-2 text-sm text-white hover:bg-red-700">
                            Revoke licence
                        </button>
                        <p class="mt-1 text-xs text-gray-500">Permanent. This key can never be used again.</p>
                    </form>
                @endif

                {{-- Rotating is offered whatever the status: a leaked secret
                     on a suspended licence still wants replacing. --}}
                <form method="POST" action="{{ route('admin.licences.rotate', $row['id']) }}"
                      onsubmit="return confirm('Regenerate this secret? The current secret stops working immediately, with no grace period for the partner to update it.')">
                    @csrf
                    <button type="submit"
                            class="mt-2 w-full rounded-md border border-amber-400 bg-amber-50 px-4 py-2 text-sm text-amber-900 hover:bg-amber-100">
                        Regenerate secret
                    </button>
                    <p class="mt-1 text-xs text-gray-500">
                        Shown once, here, and never again. The partner must update their integration before their next call.
                    </p>
                </form>

                @if(! $canSuspend && ! $canReactivate && ! $canRevoke)
                    <p class="text-sm text-gray-500">
                        This licence is revoked, which is final. Issue the partner a new one instead.
                    </p>
                @endif
            </div>
        </div>

        @if($row['notes'])
            <div class="rounded-lg bg-white shadow">
                <h3 class="border-b px-6 py-4 text-sm font-semibold uppercase text-gray-500">Notes</h3>
                <p class="px-6 py-4 text-sm whitespace-pre-line text-gray-700">{{ $row['notes'] }}</p>
            </div>
        @endif
    </div>
</div>
@endsection
