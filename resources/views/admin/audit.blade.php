@extends('layouts.admin')

@section('title', 'Audit log')

@php
    $when = fn ($value) => $value ? \Carbon\Carbon::parse($value)->format('M d, Y H:i:s') : '-';
    $outcomeTone = fn (?string $outcome) => match ($outcome) {
        'succeeded' => 'success',
        'refused' => 'warning',
        'failed' => 'danger',
        default => 'neutral',
    };
@endphp

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-semibold tracking-tight text-fg">Audit log</h1>
    <p class="mt-1 max-w-3xl text-sm text-muted">Every recorded action, by admins and users alike. Read-only: nothing here can be changed from the console.</p>
</div>

<form method="GET" action="{{ route('admin.audit') }}" class="mb-6 bg-surface rounded-card border border-line shadow-card p-4 flex flex-wrap items-end gap-4">
    <div>
        <label for="category" class="block text-xs font-medium text-muted uppercase mb-1">Category</label>
        <x-ui.select id="category" name="category">
            <option value="">Everything</option>
            @foreach($categories as $prefix => $label)
                <option value="{{ $prefix }}" @selected(($filters['category'] ?? null) === $prefix)>{{ $label }}</option>
            @endforeach
        </x-ui.select>
    </div>
    <div class="flex-1 min-w-48">
        <label for="actor" class="block text-xs font-medium text-muted uppercase mb-1">Who</label>
        <x-ui.input id="actor" name="actor" type="text" value="{{ $filters['actor'] ?? '' }}" placeholder="Email of the person who acted" />
    </div>
    <div>
        <label for="from" class="block text-xs font-medium text-muted uppercase mb-1">From</label>
        <x-ui.input id="from" name="from" type="date" value="{{ $filters['from'] ?? '' }}" />
    </div>
    <div>
        <label for="to" class="block text-xs font-medium text-muted uppercase mb-1">To</label>
        <x-ui.input id="to" name="to" type="date" value="{{ $filters['to'] ?? '' }}" />
    </div>
    <div class="flex items-center gap-2">
        <x-ui.button variant="secondary">Filter</x-ui.button>
        <x-ui.button variant="ghost" :href="route('admin.audit')">Reset</x-ui.button>
    </div>
</form>

<div class="bg-surface rounded-card border border-line shadow-card overflow-hidden">
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>When</x-ui.th>
            <x-ui.th>Who</x-ui.th>
            <x-ui.th>Action</x-ui.th>
            <x-ui.th>Record</x-ui.th>
            <x-ui.th>Details</x-ui.th>
            <x-ui.th>Address</x-ui.th>
        </x-slot:head>

        @forelse($entries as $entry)
        <tr class="hover:bg-sunken align-top">
            <td class="px-6 py-4 text-sm text-muted whitespace-nowrap">{{ $when($entry->created_at) }}</td>
            <td class="px-6 py-4 text-sm break-all">
                @if($entry->actor_id)
                    <a href="{{ route('admin.users.show', $entry->actor_id) }}" class="text-accent hover:text-accent-strong">{{ $entry->actor_email }}</a>
                @else
                    <span class="text-muted">{{ $entry->metadata['email'] ?? 'system' }}</span>
                @endif
                @if($entry->organization)
                    <div class="text-xs text-muted">{{ $entry->organization }}</div>
                @endif
            </td>
            <td class="px-6 py-4 text-sm">
                <div class="font-mono text-fg">{{ $entry->action }}</div>
                @if(isset($entry->metadata['outcome']))
                    <x-ui.badge :tone="$outcomeTone($entry->metadata['outcome'])" :label="$entry->metadata['outcome']" />
                @endif
            </td>
            <td class="px-6 py-4 text-sm text-muted">
                @if($entry->entity_type === 'User' && $entry->entity_id)
                    <a href="{{ route('admin.users.show', $entry->entity_id) }}" class="text-accent hover:text-accent-strong">User <span class="font-mono">{{ Str::limit($entry->entity_id, 8) }}</span></a>
                @elseif($entry->entity_type)
                    {{ $entry->entity_type }} <span class="font-mono">{{ Str::limit((string) $entry->entity_id, 8) }}</span>
                @else
                    -
                @endif
            </td>
            <td class="px-6 py-4 text-xs text-fg">
                @if($entry->old_values || $entry->new_values)
                    @foreach($entry->new_values as $key => $value)
                        <div><span class="font-mono">{{ $key }}</span>: {{ json_encode($entry->old_values[$key] ?? null) }} &rarr; {{ json_encode($value) }}</div>
                    @endforeach
                @endif
                @foreach(($entry->metadata['input'] ?? []) as $key => $value)
                    <div><span class="font-mono">{{ $key }}</span>: {{ Str::limit(is_scalar($value) ? (string) $value : json_encode($value), 120) }}</div>
                @endforeach
            </td>
            <td class="px-6 py-4 text-sm font-mono text-fg">{{ $entry->ip_address ?? '-' }}</td>
        </tr>
        @empty
        <x-ui.empty :columns="6">Nothing recorded for these filters</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

<div class="mt-4">
    {{ $entries->links() }}
</div>
@endsection
