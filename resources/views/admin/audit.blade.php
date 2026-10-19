@extends('layouts.admin')

@section('title', 'Audit log')

@php
    $outcomeTone = fn (?string $outcome) => match ($outcome) {
        'succeeded' => 'success',
        'refused' => 'warning',
        'failed' => 'danger',
        default => 'neutral',
    };
@endphp

@section('content')
<x-ui.page-header title="Audit log" description="Every recorded action, by admins and users alike. Read-only: nothing here can be changed from the console." />

<x-ui.list-filters :list="$list" />

<div class="bg-surface rounded-card border border-line shadow-card overflow-hidden">
    <x-ui.table :primary="[2, 1, 0]">
        <x-slot:head>
            <x-ui.th :list="$list" sort="newest">When</x-ui.th>
            <x-ui.th>Who</x-ui.th>
            <x-ui.th>Action</x-ui.th>
            <x-ui.th>Record</x-ui.th>
            <x-ui.th>Details</x-ui.th>
            <x-ui.th>Address</x-ui.th>
        </x-slot:head>

        @forelse($entries as $entry)
        <tr class="hover:bg-sunken align-top">
            <td class="px-6 py-4 text-sm text-muted whitespace-nowrap"><x-ui.date :value="$entry->created_at" time /></td>
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

{{ $entries->links() }}
@endsection
