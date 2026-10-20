@extends('layouts.admin')

@section('title', 'Feature board')

@section('content')
<x-ui.page-header title="Feature board" description="Feature requests to triage, and the board customers read: each item worded with no company named, sorted by how many companies want it." />

<x-ui.section title="Waiting for triage" :description="$untriaged->isEmpty() ? 'Every feature request is on the board.' : 'Open each to put it on the board as a new item, add it to one already there, or make it a customization.'">
    <div class="overflow-hidden rounded-card border border-line">
        <x-ui.table :primary="[2, 1, 0]">
            <x-slot:head>
                <x-ui.th>Request</x-ui.th>
                <x-ui.th>Company</x-ui.th>
                <x-ui.th>Subject</x-ui.th>
                <x-ui.th>Asked</x-ui.th>
            </x-slot:head>
            @forelse ($untriaged as $ticket)
            <tr class="hover:bg-sunken">
                <td class="px-6 py-4 font-mono text-sm"><a href="{{ route('admin.support.show', $ticket->id) }}" class="text-accent hover:underline">{{ $ticket->reference() }}</a></td>
                <td class="px-6 py-4 text-sm text-muted">{{ $ticket->organization?->name }}</td>
                <td class="px-6 py-4 text-sm text-fg">{{ $ticket->subject }}</td>
                <td class="px-6 py-4 text-sm text-muted"><x-ui.date :value="$ticket->created_at" /></td>
            </tr>
            @empty
            <x-ui.empty :columns="4">Nothing waiting</x-ui.empty>
            @endforelse
        </x-ui.table>
    </div>
</x-ui.section>

<form method="GET" action="{{ route('admin.board.index') }}" class="my-6 flex flex-wrap items-end gap-4 rounded-card border border-line bg-surface p-4 shadow-card">
    <div>
        <x-ui.field label="Stage">
            <x-ui.select id="stage" name="stage">
                <option value="">Any stage</option>
                @foreach ($stages as $option)
                    <option value="{{ $option->value }}" @selected($stage === $option)>{{ $option->label() }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>
    </div>
    <div>
        <x-ui.field label="Order">
            <x-ui.select id="sort" name="sort">
                <option value="demand" @selected($sort === 'demand')>Most wanted first</option>
                <option value="newest" @selected($sort === 'newest')>Newest first</option>
            </x-ui.select>
        </x-ui.field>
    </div>
    <x-ui.button variant="secondary">Filter</x-ui.button>
</form>

<div class="overflow-hidden rounded-card border border-line bg-surface shadow-card">
    <x-ui.table :primary="[1, 4, 2]">
        <x-slot:head>
            <x-ui.th>Item</x-ui.th>
            <x-ui.th>Title</x-ui.th>
            <x-ui.th>Stage</x-ui.th>
            <x-ui.th>On the board</x-ui.th>
            <x-ui.th>Companies</x-ui.th>
            <x-ui.th>Requests</x-ui.th>
        </x-slot:head>
        @forelse ($standings as $standing)
        <tr class="hover:bg-sunken">
            <td class="px-6 py-4 font-mono text-sm"><a href="{{ route('admin.board.show', $standing->item->id) }}" class="text-accent hover:underline">{{ $standing->item->reference() }}</a></td>
            <td class="px-6 py-4 text-sm text-fg">{{ $standing->item->title }}</td>
            <td class="px-6 py-4"><x-ui.badge :tone="$standing->item->stage->tone()" :label="$standing->item->stage->label()" /></td>
            <td class="px-6 py-4">
                <x-ui.badge :tone="$standing->item->published() ? 'success' : 'neutral'" :label="$standing->item->published() ? 'Published' : 'Draft'" />
            </td>
            <td class="px-6 py-4 text-sm tabular-nums text-fg">{{ $standing->demand }}</td>
            <td class="px-6 py-4 text-sm tabular-nums text-muted">{{ $standing->requests }}</td>
        </tr>
        @empty
        <x-ui.empty :columns="6">No items on the board</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>
@endsection
