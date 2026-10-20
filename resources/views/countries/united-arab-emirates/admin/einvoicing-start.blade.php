{{--
    A UAE organization's e-invoicing start date, for a platform admin
    (AdminEinvoicingStartController). From that date every invoice is issued
    and sent through its service provider; before it, invoices are tax
    invoices kept on the platform and never sent (UAE Electronic Invoicing
    Guidelines v1.1, 6.1). The published phases are a hint (8.2-8.3). English,
    as the console is.

    @param \App\Domains\Organization\Models\Organization $organization
    @param \Carbon\CarbonImmutable|null $start
    @param bool $inScope
    @param bool $appointed
    @param list<array{who: string, appoint_by: string, starts_on: string}> $phases
    @param string $voluntaryFrom
--}}
@extends('layouts.admin')

@section('title', 'E-invoicing start date')

@section('content')
<x-ui.page-header title="E-invoicing start date" :description="$organization->name">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[
            ['label' => 'Organizations', 'href' => route('admin.organizations')],
            ['label' => $organization->name, 'href' => route('admin.organization.detail', $organization->id)],
            ['label' => 'E-invoicing start date'],
        ]" />
    </x-slot:breadcrumb>
</x-ui.page-header>

<div class="mb-6 max-w-2xl rounded-card border border-line bg-surface p-6 shadow-card" data-einvoicing-stage>
    <p class="text-sm text-fg">
        @if ($start === null)
            Before e-invoicing: no start date set. Its invoices are tax invoices kept here, and none is sent to a service provider.
        @elseif (! $inScope)
            Before e-invoicing. From {{ $start->toDateString() }}, every invoice is sent through its service provider.
        @else
            E-invoicing via provider from {{ $start->toDateString() }}.
        @endif
    </p>
    @if ($start !== null && ! $appointed)
        <p class="mt-2 text-sm text-warning">No service provider is recorded as appointed. From the start date, issuing is refused until one is.</p>
    @endif
</div>

<form method="POST" action="{{ route('admin.organizations.einvoicing-start.update', $organization->id) }}" class="max-w-2xl space-y-4"
      data-confirm="Save this organization's e-invoicing start date?"
      data-confirm-detail="From this date its invoices are sent through its service provider. Invoices issued before it are never sent."
      data-confirm-action="Save start date">
    @csrf
    <x-ui.field label="E-invoicing start date" error="einvoicing_start">
        <x-ui.date-input name="einvoicing_start" value="{{ old('einvoicing_start', $start?->toDateString()) }}" />
        <span class="mt-1 block text-xs font-normal text-muted">Empty: not yet in scope. Voluntary e-invoicing is possible from {{ $voluntaryFrom }}.</span>
    </x-ui.field>

    <div class="text-xs text-muted">
        <p class="font-medium">Published phases, for guidance:</p>
        <ul class="mt-1 list-disc space-y-1 ps-5">
            @foreach ($phases as $phase)
                <li>{{ $phase['who'] }}: appoint a service provider by {{ $phase['appoint_by'] }}, e-invoicing from {{ $phase['starts_on'] }}.</li>
            @endforeach
        </ul>
    </div>

    <x-ui.button variant="primary">Save start date</x-ui.button>
</form>
@endsection
