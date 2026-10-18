@extends('layouts.admin')

@section('title', 'Licence')

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
        <a href="{{ route('admin.licences.index') }}" class="text-sm text-accent hover:text-accent-strong">&larr; All licences</a>
        <h2 class="mt-1 text-2xl font-semibold text-fg">{{ $row['organization_name'] }}</h2>
        <p class="text-muted">{{ $row['contact_email'] }}</p>
    </div>
    <div class="flex items-center gap-3">
        <x-ui.badge :state="$status ?? $row['status']" :label="$status?->getLabel()" />
        <span class="text-xs text-muted">
            {{ $licence['is_valid'] ? 'Serving API traffic' : 'Not serving API traffic' }}
        </span>
    </div>
</div>

<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <div class="rounded-card bg-surface border border-line shadow-card">
            <h3 class="border-b px-6 py-4 text-sm font-semibold uppercase text-muted">Partner</h3>
            <dl class="grid gap-4 px-6 py-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs uppercase text-muted">Organization</dt>
                    <dd class="text-sm text-fg">{{ $row['organization_name'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-muted">VAT number</dt>
                    <dd class="text-sm text-fg">{{ $row['organization_vat'] ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-muted">Contact email</dt>
                    <dd class="text-sm text-fg">{{ $row['contact_email'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-muted">Contact phone</dt>
                    <dd class="text-sm text-fg">{{ $row['contact_phone'] ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-muted">Tenant</dt>
                    {{-- ValidateLicense scopes every partner call to this org, so a
                         licence without one authenticates and then reaches nothing. --}}
                    <dd class="font-mono text-xs break-all text-fg">
                        {{ $row['org_id'] ?? 'Not linked to an organization' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-muted">Environment</dt>
                    <dd class="text-sm text-fg">{{ $row['environment'] }}</dd>
                </div>
            </dl>
        </div>

        <div class="rounded-card bg-surface border border-line shadow-card">
            <h3 class="border-b px-6 py-4 text-sm font-semibold uppercase text-muted">Credentials</h3>
            <dl class="space-y-4 px-6 py-4">
                <div>
                    <dt class="text-xs uppercase text-muted">API key</dt>
                    <dd class="font-mono text-sm break-all text-fg">{{ $row['api_key'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-muted">API secret</dt>
                    {{-- Hashed on save and hidden on the model, so it cannot be read
                         back. Replacing it is regenerateSecret(), which has no screen. --}}
                    <dd class="text-sm text-muted">Stored as a hash and cannot be shown again</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-muted">Scopes</dt>
                    <dd class="mt-1 flex flex-wrap gap-2">
                        @forelse(($row['scopes'] ?? []) as $scope)
                            <span class="rounded bg-sunken px-2 py-1 font-mono text-xs text-fg">{{ $scope }}</span>
                        @empty
                            <span class="text-sm text-muted">No scopes - this key can call nothing</span>
                        @endforelse
                    </dd>
                </div>
            </dl>
        </div>

        <div class="rounded-card bg-surface border border-line shadow-card">
            <h3 class="border-b px-6 py-4 text-sm font-semibold uppercase text-muted">
                Quotas and usage ({{ $usage['month'] }})
            </h3>
            <dl class="grid gap-4 px-6 py-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs uppercase text-muted">Tier</dt>
                    <dd class="text-sm text-fg">{{ $tier?->getDisplayName() ?? $row['tier'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-muted">Invoices this month</dt>
                    <dd class="text-sm text-fg">
                        {{ number_format((int) $usage['totals']['invoices_submitted']) }}
                        @if ((int) $row['invoices_per_month'] >= \App\Domains\Licensing\Models\License::UNLIMITED)
                            <span class="text-xs text-muted">(unlimited)</span>
                        @else
                            of {{ number_format((int) $usage['limits']['invoices_per_month']) }}
                            <span class="text-xs text-muted">({{ $usage['utilization']['invoices_percent'] }}%)</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-muted">Calls per day</dt>
                    <dd class="text-sm text-fg">{{ (int) $row['calls_per_day'] >= \App\Domains\Licensing\Models\License::UNLIMITED ? 'Unlimited' : number_format((int) $row['calls_per_day']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-muted">Calls per minute</dt>
                    <dd class="text-sm text-fg">{{ number_format((int) $row['calls_per_min']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-muted">Organizations allowed</dt>
                    <dd class="text-sm text-fg">{{ number_format((int) $row['max_organizations']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-muted">Clearance success rate</dt>
                    <dd class="text-sm text-fg">{{ $usage['utilization']['success_rate'] }}%</dd>
                </div>
            </dl>
        </div>

        <div class="rounded-card bg-surface border border-line shadow-card">
            <h3 class="border-b px-6 py-4 text-sm font-semibold uppercase text-muted">Dates</h3>
            <dl class="grid gap-4 px-6 py-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs uppercase text-muted">Issued</dt>
                    <dd class="text-sm text-fg">{{ $when($row['created_at'] ?? null) }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-muted">Activated</dt>
                    <dd class="text-sm text-fg">{{ $when($row['activated_at'] ?? null) }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-muted">Expires</dt>
                    <dd class="text-sm text-fg">
                        {{ $row['expires_at'] ? $when($row['expires_at']) : 'Never' }}
                        @if($licence['days_until_expiry'] !== null)
                            <span class="text-xs text-muted">
                                ({{ (int) floor((float) $licence['days_until_expiry']) }} days)
                            </span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-muted">Suspended</dt>
                    <dd class="text-sm text-fg">{{ $when($row['suspended_at'] ?? null) }}</dd>
                </div>
            </dl>
            @if($row['suspension_reason'])
                <div class="border-t px-6 py-4">
                    <dt class="text-xs uppercase text-muted">Reason on record</dt>
                    <dd class="text-sm text-fg">{{ $row['suspension_reason'] }}</dd>
                </div>
            @endif
        </div>
    </div>

    <div class="space-y-6">
        {{-- The customer's own allowances. Empty is the platform's default,
             unlimited for now; the per-minute cap is a safety limit and is
             not set here. --}}
        @admincan('licences.manage')
        <form method="POST" action="{{ route('admin.licences.allowances', $row['id']) }}"
              class="rounded-card bg-surface border border-line shadow-card">
            @csrf
            <h3 class="border-b border-line px-6 py-4 text-sm font-semibold uppercase text-muted">Allowances</h3>
            <div class="space-y-4 px-6 py-4">
                @foreach (['invoices_per_month' => 'Invoices a month', 'calls_per_day' => 'API calls a day'] as $allowance => $label)
                    @php($unlimited = (int) $row[$allowance] >= \App\Domains\Licensing\Models\License::UNLIMITED)
                    <x-ui.field :label="$label" :hint="'Empty for unlimited. Its administrators are warned at 80% and 100%.'" :error="$allowance">
                        <x-ui.input type="number" name="{{ $allowance }}" min="1" max="4294967294"
                                    value="{{ old($allowance, $unlimited ? '' : $row[$allowance]) }}" placeholder="Unlimited" />
                    </x-ui.field>
                @endforeach
                <x-ui.button variant="secondary" class="w-full">Save allowances</x-ui.button>
            </div>
        </form>
        @endadmincan

        <div class="rounded-card bg-surface border border-line shadow-card">
            <h3 class="border-b px-6 py-4 text-sm font-semibold uppercase text-muted">Lifecycle</h3>
            <div class="space-y-6 px-6 py-4">
                @if($canSuspend)
                    @admincan('licences.manage')
                    <form method="POST" action="{{ route('admin.licences.suspend', $row['id']) }}"
                          data-confirm-tone="danger" data-confirm-action="Suspend"
                          data-confirm="Suspend this licence? The partner stops being able to call the API the moment you confirm.">
                        @csrf
                        <label for="suspend-reason" class="block text-xs font-medium uppercase text-muted">Reason</label>
                        <x-ui.input id="suspend-reason" name="reason" type="text" maxlength="255" required
                               placeholder="Why the partner is being cut off" />
                        <x-ui.button variant="secondary" class="mt-2 w-full">Suspend licence</x-ui.button>
                        <p class="mt-1 text-xs text-muted">Reversible. Invoices already submitted are untouched.</p>
                    </form>
                    @endadmincan
                @endif

                @if($canReactivate)
                    @admincan('licences.manage')
                    <form method="POST" action="{{ route('admin.licences.reactivate', $row['id']) }}">
                        @csrf
                        <x-ui.button class="w-full">Reactivate licence</x-ui.button>
                        <p class="mt-1 text-xs text-muted">Clears the suspension and lets the partner call again.</p>
                    </form>
                    @endadmincan
                @endif

                @if($canRevoke)
                    @admincan('licences.manage')
                    <form method="POST" action="{{ route('admin.licences.revoke', $row['id']) }}"
                          data-confirm-tone="danger" data-confirm-action="Revoke"
                          data-confirm="Revoke this licence? This is permanent and the partner integration stops working now.">
                        @csrf
                        <label for="revoke-reason" class="block text-xs font-medium uppercase text-muted">Reason</label>
                        <x-ui.input id="revoke-reason" name="reason" type="text" maxlength="255" required
                               placeholder="Why the licence is being withdrawn" />
                        <x-ui.button variant="danger" class="mt-2 w-full">Revoke licence</x-ui.button>
                        <p class="mt-1 text-xs text-muted">Permanent. This key can never be used again.</p>
                    </form>
                    @endadmincan
                @endif

                {{-- Rotating is offered whatever the status: a leaked secret
                     on a suspended licence still wants replacing. --}}
                @admincan('licences.manage')
                <form method="POST" action="{{ route('admin.licences.rotate', $row['id']) }}"
                      data-confirm-tone="danger" data-confirm-action="Regenerate"
                      data-confirm="Regenerate this secret? The current secret stops working immediately, with no grace period for the partner to update it.">
                    @csrf
                    <x-ui.button variant="secondary" class="mt-2 w-full">Regenerate secret</x-ui.button>
                    <p class="mt-1 text-xs text-muted">
                        Shown once, here, and never again. The partner must update their integration before their next call.
                    </p>
                </form>
                @endadmincan

                @if(! $canSuspend && ! $canReactivate && ! $canRevoke)
                    <p class="text-sm text-muted">
                        This licence is revoked, which is final. Issue the partner a new one instead.
                    </p>
                @endif
            </div>
        </div>

        @if($row['notes'])
            <div class="rounded-card bg-surface border border-line shadow-card">
                <h3 class="border-b px-6 py-4 text-sm font-semibold uppercase text-muted">Notes</h3>
                <p class="px-6 py-4 text-sm whitespace-pre-line text-fg">{{ $row['notes'] }}</p>
            </div>
        @endif
    </div>
</div>
@endsection
