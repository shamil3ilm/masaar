@extends('layouts.admin')

@section('title', 'Issue a licence')

@section('content')
<div class="mb-6">
    <a href="{{ route('admin.licences.index') }}" class="text-sm text-accent hover:text-accent-strong">&larr; All licences</a>
    <h2 class="mt-1 text-2xl font-semibold text-fg">Issue a licence</h2>
    <p class="text-muted">
        Creates the API key and secret for a partner. The secret is shown once, on the
        next page, and is never recoverable afterwards.
    </p>
</div>

@admincan('licences.manage')
<form method="POST" action="{{ route('admin.licences.store') }}" class="max-w-3xl space-y-6">
    @csrf

    <div class="rounded-card bg-surface border border-line shadow-card">
        <h3 class="border-b px-6 py-4 text-sm font-semibold uppercase text-muted">Partner</h3>
        <div class="grid gap-4 px-6 py-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label for="organization_name" class="block text-xs font-medium uppercase text-muted">Organization name</label>
                <x-ui.input id="organization_name" name="organization_name" type="text" maxlength="255" required
                       value="{{ old('organization_name') }}" />
                @error('organization_name')
                    <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="contact_email" class="block text-xs font-medium uppercase text-muted">Contact email</label>
                <x-ui.input id="contact_email" name="contact_email" type="email" maxlength="255" required
                       value="{{ old('contact_email') }}" />
                @error('contact_email')
                    <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="contact_phone" class="block text-xs font-medium uppercase text-muted">Contact phone</label>
                <x-ui.input id="contact_phone" name="contact_phone" type="text" maxlength="50"
                       value="{{ old('contact_phone') }}" />
                @error('contact_phone')
                    <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="organization_vat" class="block text-xs font-medium uppercase text-muted">VAT number</label>
                <x-ui.input id="organization_vat" name="organization_vat" type="text" maxlength="50"
                       value="{{ old('organization_vat') }}" />
                @error('organization_vat')
                    <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="org_id" class="block text-xs font-medium uppercase text-muted">Organization id</label>
                <x-ui.input id="org_id" name="org_id" type="text" maxlength="36"
                       value="{{ old('org_id') }}" placeholder="Leave blank if the tenant does not exist yet" class="font-mono" />
                {{-- Every partner call is scoped to this organization, so a licence
                     without one authenticates and then reaches no tenant. --}}
                <p class="mt-1 text-xs text-muted">
                    The tenant this key may act for. Until it is set the key authenticates but reaches no data.
                </p>
                @error('org_id')
                    <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </div>

    <div class="rounded-card bg-surface border border-line shadow-card">
        <h3 class="border-b px-6 py-4 text-sm font-semibold uppercase text-muted">Plan</h3>
        <div class="grid gap-4 px-6 py-4 sm:grid-cols-2">
            <div>
                <label for="tier" class="block text-xs font-medium uppercase text-muted">Tier</label>
                <x-ui.select id="tier" name="tier" required>
                    @foreach (\App\Domains\Licensing\Enums\LicenseTier::cases() as $case)
                        @php
                            // The Unlimited tier's quota is PHP_INT_MAX, which
                            // number_format renders as a 19-digit number.
                            $quota = (int) $case->getDefaults()['invoices_per_month'];
                            $label = $quota === PHP_INT_MAX ? 'no invoice limit' : number_format($quota).' invoices / month';
                        @endphp
                        <option value="{{ $case->value }}" @selected(old('tier') === $case->value)>
                            {{ $case->getDisplayName() }} ({{ $label }})
                        </option>
                    @endforeach
                </x-ui.select>
                {{-- The tier decides the quotas, scopes and features; nothing here
                     overrides them, so a bespoke limit is still a service call. --}}
                <p class="mt-1 text-xs text-muted">Sets the quotas, scopes and features for this licence.</p>
                @error('tier')
                    <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="expires_at" class="block text-xs font-medium uppercase text-muted">Expires</label>
                <x-ui.input id="expires_at" name="expires_at" type="date" value="{{ old('expires_at') }}" />
                <p class="mt-1 text-xs text-muted">Leave blank for a licence that never expires.</p>
                @error('expires_at')
                    <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                @enderror
            </div>
            <div class="sm:col-span-2">
                <label for="notes" class="block text-xs font-medium uppercase text-muted">Notes</label>
                <x-ui.textarea id="notes" name="notes" rows="3" maxlength="2000">{{ old('notes') }}</x-ui.textarea>
                @error('notes')
                    <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </div>

    <div class="flex items-center gap-3">
        <x-ui.button>Issue licence</x-ui.button>
        <x-ui.button variant="ghost" :href="route('admin.licences.index')">Cancel</x-ui.button>
        <p class="text-xs text-muted">The licence starts active and the partner can call the API immediately.</p>
    </div>
</form>
@endadmincan
@endsection
