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
    @param \Illuminate\Support\Collection $waiting  documents signed in this environment and not yet filed
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

@if ($waiting->isNotEmpty())
    <div class="mb-6 max-w-2xl rounded-card border border-line bg-surface p-6 shadow-card" data-unfiled>
        <p class="text-sm font-medium text-warning">
            {{ $waiting->count() }} {{ $waiting->count() === 1 ? 'document' : 'documents' }} signed in {{ $environment }} {{ $waiting->count() === 1 ? 'has' : 'have' }} not been filed with ZATCA yet. The environment cannot be switched until {{ $waiting->count() === 1 ? 'it is' : 'they are' }}.
        </p>
        <ul class="mt-3 space-y-1 text-sm">
            @foreach ($waiting as $document)
                <li>
                    <a href="{{ route('admin.alerts') }}#invoice-{{ $document['id'] }}" class="text-accent hover:text-accent-strong">{{ $document['invoice_number'] }}</a>
                    <span class="text-muted">- {{ match ($document['reason']) { 'offline_queue' => 'queued offline', 'overdue' => 'report overdue', default => 'waiting for ZATCA' } }}</span>
                </li>
            @endforeach
        </ul>
    </div>
@endif

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

    {{-- A switch to or from production waits for a second admin (EnvironmentSwitchActions). --}}
    @include('admin.partials.approval-reason', ['action' => \App\Domains\Compliance\Countries\SaudiArabia\Onboarding\EnvironmentSwitchActions::PRODUCTION])

    <x-ui.button variant="primary">Save environment</x-ui.button>
</form>

{{-- The organization's e-invoices for a period, as one ZIP named as ZATCA names them (DocumentArchive). --}}
<form method="GET" action="{{ route('admin.organizations.zatca-archive', $organization->id) }}" class="mt-8 max-w-2xl space-y-4" data-zatca-archive>
    <h2 class="text-lg font-semibold text-fg">E-invoice archive</h2>
    <p class="text-sm text-muted">Signed and cleared XML, PDFs, a manifest and a SHA-256 of every file, for up to a year of issue dates. The export is on the organization's activity record.</p>
    <div class="grid gap-4 sm:grid-cols-2">
        <x-ui.field label="From" error="from">
            <x-ui.date-input name="from" required value="{{ now()->startOfMonth()->toDateString() }}" />
        </x-ui.field>
        <x-ui.field label="To" error="to">
            <x-ui.date-input name="to" required value="{{ now()->toDateString() }}" />
        </x-ui.field>
    </div>
    <x-ui.button variant="secondary">Download archive</x-ui.button>
</form>
@endsection
