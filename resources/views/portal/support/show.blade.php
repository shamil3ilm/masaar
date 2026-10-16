@extends('layouts.portal')

@section('title', $ticket->reference())

@section('content')
<div class="mb-6">
    <p class="text-sm"><a href="{{ route('portal.support.index') }}" class="text-teal-700 dark:text-teal-300 hover:underline">&larr; Support</a></p>
    <div class="mt-2 flex flex-wrap items-center gap-3">
        <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ $ticket->subject }}</h2>
        <x-ui.badge :tone="$ticket->status->tone()" :label="$ticket->status->label()" />
    </div>
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $ticket->reference() }} &middot; {{ $ticket->category->label() }} &middot; opened {{ $ticket->created_at->format('M d, Y') }}</p>
</div>

<div class="max-w-3xl space-y-6">
    <x-support-thread :ticket="$ticket" attachment-route="portal.support.attachment" />

    <form method="POST" action="{{ route('portal.support.reply', $ticket->id) }}" enctype="multipart/form-data"
          class="space-y-3 rounded-lg bg-white dark:bg-gray-900 p-4 shadow">
        @csrf
        <x-ui.field label="Reply">
            <x-ui.textarea name="body" rows="5" maxlength="10000" required>{{ old('body') }}</x-ui.textarea>
        </x-ui.field>
        <x-ui.field label="Attachments (optional)">
            <x-ui.input type="file" name="files[]" multiple accept=".pdf,.png,.jpg,.jpeg,.webp,.txt,.csv,.xml" />
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Up to {{ $maxFiles }} files, 5 MB each.</p>
        </x-ui.field>
        <x-ui.button>Send reply</x-ui.button>
    </form>

    @if ($ticket->status !== \App\Domains\Support\Enums\TicketStatus::Resolved)
        <form method="POST" action="{{ route('portal.support.resolve', $ticket->id) }}">
            @csrf
            <x-ui.button variant="outline">My problem is solved</x-ui.button>
        </form>
    @endif
</div>
@endsection
