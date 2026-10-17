@extends('layouts.portal')

@section('title', __('Support'))

@section('content')
<div class="mb-6 flex flex-wrap items-start justify-between gap-4">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight text-fg">{{ __('Support') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-muted">{{ __('Ask us anything about ZATCA, your invoices or your account. Everyone in your company sees these tickets.') }}</p>
    </div>
    <x-ui.button :href="route('portal.support.create')">{{ __('New ticket') }}</x-ui.button>
</div>

<div class="bg-surface rounded-card border border-line shadow-card overflow-hidden">
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>{{ __('Ticket') }}</x-ui.th>
            <x-ui.th>{{ __('Subject') }}</x-ui.th>
            <x-ui.th>{{ __('Status') }}</x-ui.th>
            <x-ui.th>{{ __('Last activity') }}</x-ui.th>
        </x-slot:head>
        @forelse ($tickets as $ticket)
        <tr class="hover:bg-sunken">
            <td class="px-6 py-4 text-sm font-mono">
                <a href="{{ route('portal.support.show', $ticket->id) }}" class="text-accent hover:underline">{{ $ticket->reference() }}</a>
            </td>
            <td class="px-6 py-4 text-sm text-fg">
                {{ $ticket->subject }}
                @if ($ticket->priority === \App\Domains\Support\Enums\TicketPriority::Urgent)
                    <x-ui.badge tone="danger" :label="__('Urgent')" />
                @endif
            </td>
            <td class="px-6 py-4"><x-ui.badge :tone="$ticket->status->tone()" :label="$ticket->status->label()" /></td>
            <td class="px-6 py-4 text-sm text-muted">{{ $ticket->last_activity_at->diffForHumans() }}</td>
        </tr>
        @empty
        <x-ui.empty :columns="4">{{ __('No tickets yet') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

<div class="mt-4">
    {{ $tickets->links() }}
</div>
@endsection
