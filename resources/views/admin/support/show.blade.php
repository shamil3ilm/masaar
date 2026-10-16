@extends('layouts.admin')

@section('title', $ticket->reference())

@section('content')
<div class="mb-6">
    <nav class="text-sm text-gray-500 dark:text-gray-400">
        <a href="{{ route('admin.support.index') }}" class="hover:text-teal-700 dark:hover:text-teal-300">Support</a>
        <span>/</span>
        <span>{{ $ticket->reference() }}</span>
    </nav>
    <div class="mt-2 flex flex-wrap items-center gap-3">
        <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ $ticket->subject }}</h2>
        <x-ui.badge :tone="$ticket->status->tone()" :label="$ticket->status->label()" />
        @if ($ticket->priority === \App\Domains\Support\Enums\TicketPriority::Urgent)
            <x-ui.badge tone="danger" label="Urgent" />
        @endif
    </div>
    <p class="text-sm text-gray-500 dark:text-gray-400">
        {{ $ticket->category->label() }} &middot;
        @if ($ticket->organization)
            <a href="{{ route('admin.organization.detail', $ticket->org_id) }}" class="text-teal-700 dark:text-teal-300 hover:underline">{{ $ticket->organization->name }}</a>
        @endif
        &middot; opened by
        @if ($ticket->opener)
            <a href="{{ route('admin.users.show', $ticket->opened_by) }}" class="text-teal-700 dark:text-teal-300 hover:underline">{{ $ticket->opener->name }}</a>
        @else
            a deleted user
        @endif
        on {{ $ticket->created_at->format('M d, Y H:i') }}
    </p>
</div>

<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <x-support-thread :ticket="$ticket" attachment-route="admin.support.attachment" />

        @admincan('support.manage')
        <form method="POST" action="{{ route('admin.support.reply', $ticket->id) }}" enctype="multipart/form-data"
              class="space-y-3 rounded-lg bg-white dark:bg-gray-900 p-4 shadow">
            @csrf
            <x-ui.field label="Reply">
                <x-ui.textarea name="body" rows="5" maxlength="10000" required>{{ old('body') }}</x-ui.textarea>
            </x-ui.field>
            <x-ui.field label="Attachments (optional)">
                <x-ui.input type="file" name="files[]" multiple accept=".pdf,.png,.jpg,.jpeg,.webp,.txt,.csv,.xml" />
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Up to {{ $maxFiles }} files, 5 MB each.</p>
            </x-ui.field>
            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                <input type="hidden" name="internal" value="0">
                <input type="checkbox" name="internal" value="1">
                Internal note - the customer does not see it
            </label>
            <x-ui.button>Send</x-ui.button>
        </form>
        @endadmincan
    </div>

    <div class="space-y-6">
        @admincan('support.manage')
        <form method="POST" action="{{ route('admin.support.assign', $ticket->id) }}" class="space-y-3 rounded-lg bg-white dark:bg-gray-900 p-4 shadow">
            @csrf
            <x-ui.field label="Assigned to">
                <x-ui.select name="assigned_to">
                    <option value="">Nobody</option>
                    @foreach ($assignees as $assignee)
                        <option value="{{ $assignee->id }}" @selected($ticket->assigned_to === $assignee->id)>{{ $assignee->name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.button variant="outline" class="w-full">Save</x-ui.button>
        </form>

        <form method="POST" action="{{ route('admin.support.status', $ticket->id) }}" class="space-y-3 rounded-lg bg-white dark:bg-gray-900 p-4 shadow">
            @csrf
            <x-ui.field label="Status">
                <x-ui.select name="status">
                    @foreach ($statuses as $option)
                        <option value="{{ $option->value }}" @selected($ticket->status === $option)>{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.button variant="outline" class="w-full">Change status</x-ui.button>
        </form>
        @else
        <div class="rounded-lg bg-white dark:bg-gray-900 p-4 text-sm text-gray-500 dark:text-gray-400 shadow">
            Assigned to {{ $ticket->assignee?->name ?? 'nobody' }}.
        </div>
        @endadmincan
    </div>
</div>
@endsection
