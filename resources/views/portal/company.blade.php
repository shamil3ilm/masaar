@extends('layouts.portal')

@section('title', __('Company'))

@section('content')
{{-- The country's own form: its module's SellerRules::companyView(), given companyData(). --}}
@include($form, ['organization' => $organization, ...$formData])

{{-- What an invoice that does not say is priced by. A select, not a
     checkbox, as on the invoice form. --}}
<form method="POST" action="{{ route('portal.company.pricing') }}" class="mt-8">
    @csrf
    <x-ui.section :title="__('Prices')" collapse>
    <div class="space-y-3">
        @php($inclusive = (string) old('prices_include_vat', $organization->prices_include_vat ? '1' : '0'))
        <x-ui.field :label="__('Prices')" :hint="__('New invoices follow this unless they say otherwise. Issued invoices keep what they were issued with.')" error="prices_include_vat">
            <x-ui.select name="prices_include_vat" id="prices_include_vat">
                <option value="0" @selected($inclusive === '0')>{{ __('Exclude VAT - VAT is added to them') }}</option>
                <option value="1" @selected($inclusive === '1')>{{ __('Include VAT - the customer pays exactly these') }}</option>
            </x-ui.select>
        </x-ui.field>
        <x-ui.button variant="secondary">{{ __('Save') }}</x-ui.button>
    </div>
    </x-ui.section>
</form>

{{-- What the business mostly is: it suggests defaults - the invoice type,
     prices with VAT in them, the paper, the setup steps - and never limits
     anything (BusinessTypes). Only the types this country serves, each
     with the country's note where it has one (Sectors). --}}
<form method="POST" action="{{ route('portal.company.business-type') }}" class="mt-8">
    @csrf
    <x-ui.section :title="__('Business type')" collapse>
    <div class="space-y-3">
        @php($chosenType = (string) old('business_type', $organization->business_type))
        <x-ui.field :label="__('What your business mostly is')" :hint="__('Sets the defaults new invoices, receipts and the dashboard start with. Every feature stays open to you whatever you choose.')" error="business_type">
            <x-ui.select searchable name="business_type" id="business_type">
                <option value="">{{ __('Not set') }}</option>
                @foreach ($businessTypes as $type)
                    <option value="{{ $type->key }}" @selected($chosenType === $type->key)>{{ $type->name() }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>
        @foreach (collect($businessTypes)->filter(fn ($type) => $type->note !== null)->groupBy('note') as $note => $types)
            <p class="text-xs text-muted">{{ $types->map(fn ($type) => $type->name())->join(', ') }}: {{ $note }}</p>
        @endforeach
        <x-ui.button variant="secondary">{{ __('Save') }}</x-ui.button>
    </div>
    </x-ui.section>
</form>

<form method="POST" action="{{ route('portal.company.security') }}" class="mt-8">
    @csrf
    <x-ui.section :title="__('Sign-in security')" collapse>
    <div class="space-y-3">
        <x-ui.switch name="require_two_factor" value="1" off="0" :checked="(bool) $organization->require_two_factor">
            {{ __('Require two-factor authentication for everyone in :organization. Members without it are sent to set it up before they can use the portal.', ['organization' => $organization->name]) }}
        </x-ui.switch>
        <x-ui.button variant="secondary">{{ __('Save') }}</x-ui.button>
    </div>
    </x-ui.section>
</form>
@endsection
