{{--
    The ZATCA environment a Saudi organization works in, for a platform admin
    (AdminEnvironmentController): the developer portal (sandbox), simulation
    or production. Each onboards a device afresh with its own OTP, so a
    switch archives the organization's credentials and it onboards again
    (EnvironmentSwitch). English, as the console is.

    @param \App\Domains\Organization\Models\Organization $organization
    @param string $environment
    @param bool $chosen
    @param string $default
    @param list<string> $environments
--}}
@extends('layouts.admin')

@section('title', 'ZATCA environment')

@section('content')
<x-ui.page-header title="ZATCA environment" :description="$organization->name">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[
            ['label' => 'Organizations', 'href' => route('admin.organizations')],
            ['label' => $organization->name, 'href' => route('admin.organization.detail', $organization->id)],
            ['label' => 'ZATCA environment'],
        ]" />
    </x-slot:breadcrumb>
</x-ui.page-header>

<div class="mb-6 max-w-2xl rounded-card border border-line bg-surface p-6 shadow-card" data-zatca-environment="{{ $environment }}">
    <p class="text-sm text-fg">
        Works in <strong>{{ $environment }}</strong>{{ $chosen ? '' : ', the deployment default' }}.
        @if ($organization->zatca_onboarded)
            Onboarded there.
        @else
            Not onboarded there yet.
        @endif
    </p>
    <p class="mt-2 text-sm text-muted">
        Switching archives every certificate it and its tills hold - nothing is deleted - and leaves it not onboarded:
        its administrators onboard again with an OTP from the new environment's Fatoora portal. Documents signed in the old
        environment are not filed in the new one.
    </p>
</div>

<form method="POST" action="{{ route('admin.organizations.zatca-environment.update', $organization->id) }}" class="max-w-2xl space-y-4"
      data-confirm="Switch this organization's ZATCA environment?"
      data-confirm-detail="Its certificates are archived and it must onboard again in the new environment."
      data-confirm-action="Switch environment">
    @csrf
    <x-ui.field label="Environment" error="environment">
        <x-ui.select name="environment" required>
            @foreach ($environments as $option)
                <option value="{{ $option }}" @selected(old('environment', $environment) === $option)>{{ $option }}{{ $option === $default ? ' (deployment default)' : '' }}</option>
            @endforeach
        </x-ui.select>
    </x-ui.field>

    <x-ui.button variant="primary">Save environment</x-ui.button>
</form>
@endsection
