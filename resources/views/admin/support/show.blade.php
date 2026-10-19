@extends('layouts.admin')

@section('title', $ticket->reference())

@section('content')
<x-ui.page-header :title="$ticket->subject">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => 'Support', 'href' => route('admin.support.index')], ['label' => $ticket->reference()]]" />
    </x-slot:breadcrumb>
    <x-slot:meta>
        <x-ui.badge :tone="$ticket->status->tone()" :label="$ticket->status->label()" />
        @if ($ticket->priority === \App\Domains\Support\Enums\TicketPriority::Urgent)
            <x-ui.badge tone="danger" label="Urgent" />
        @endif
    </x-slot:meta>
    <x-slot:description>
        {{ $ticket->category->label() }} &middot;
        @if ($ticket->organization)
            <a href="{{ route('admin.organization.detail', $ticket->org_id) }}" class="text-accent hover:underline">{{ $ticket->organization->name }}</a>
        @endif
        &middot; opened by
        @if ($ticket->opener)
            <a href="{{ route('admin.users.show', $ticket->opened_by) }}" class="text-accent hover:underline">{{ $ticket->opener->name }}</a>
        @else
            a deleted user
        @endif
        on <x-ui.date :value="$ticket->created_at" time />
    </x-slot:description>
</x-ui.page-header>

<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <x-support-thread :ticket="$ticket" attachment-route="admin.support.attachment" />

        @admincan('support.manage')
        <form method="POST" action="{{ route('admin.support.reply', $ticket->id) }}" enctype="multipart/form-data"
              class="space-y-3 rounded-card bg-surface p-4 border border-line shadow-card">
            @csrf
            <x-ui.field label="Reply">
                <x-ui.textarea name="body" rows="5" maxlength="10000" required>{{ old('body') }}</x-ui.textarea>
            </x-ui.field>
            <x-ui.field label="Attachments (optional)">
                <x-ui.input type="file" name="files[]" multiple accept=".pdf,.png,.jpg,.jpeg,.webp,.txt,.csv,.xml" />
                <p class="mt-1 text-xs text-muted">Up to {{ $maxFiles }} files, 5 MB each.</p>
            </x-ui.field>
            <input type="hidden" name="internal" value="0">
            <x-ui.checkbox name="internal" value="1">Internal note - the customer does not see it</x-ui.checkbox>
            <x-ui.button>Send</x-ui.button>
        </form>
        @endadmincan
    </div>

    <div class="space-y-6">
        @admincan('support.manage')
        <form method="POST" action="{{ route('admin.support.assign', $ticket->id) }}" class="space-y-3 rounded-card bg-surface p-4 border border-line shadow-card">
            @csrf
            <x-ui.field label="Assigned to">
                <x-ui.select name="assigned_to">
                    <option value="">Nobody</option>
                    @foreach ($assignees as $assignee)
                        <option value="{{ $assignee->id }}" @selected($ticket->assigned_to === $assignee->id)>{{ $assignee->name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.button variant="secondary" class="w-full">Save</x-ui.button>
        </form>

        <form method="POST" action="{{ route('admin.support.status', $ticket->id) }}" class="space-y-3 rounded-card bg-surface p-4 border border-line shadow-card">
            @csrf
            <x-ui.field label="Status">
                <x-ui.select name="status">
                    @foreach ($statuses as $option)
                        <option value="{{ $option->value }}" @selected($ticket->status === $option)>{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.button variant="secondary" class="w-full">Change status</x-ui.button>
        </form>
        @else
        <div class="rounded-card bg-surface p-4 text-sm text-muted border border-line shadow-card">
            Assigned to {{ $ticket->assignee?->name ?? 'nobody' }}.
        </div>
        @endadmincan
    </div>
</div>
@endsection
