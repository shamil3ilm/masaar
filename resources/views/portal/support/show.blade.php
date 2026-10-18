@extends('layouts.portal')

@section('title', $ticket->reference())

@section('content')
<div class="mb-6">
    <p class="text-sm"><a href="{{ route('portal.support.index') }}" class="text-accent hover:underline"><span class="inline-block rtl:rotate-180">&larr;</span> {{ __('Support') }}</a></p>
    <div class="mt-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold tracking-tight text-fg">{{ $ticket->subject }}</h1>
        <x-ui.badge :tone="$ticket->status->tone()" :label="$ticket->status->label()" />
    </div>
    <p class="text-sm text-muted"><span dir="ltr">{{ $ticket->reference() }}</span> &middot; {{ $ticket->category->label() }} &middot; {{ __('opened :date', ['date' => $ticket->created_at->translatedFormat('M d, Y')]) }}</p>
</div>

<div class="max-w-3xl space-y-6">
    <x-support-thread :ticket="$ticket" attachment-route="portal.support.attachment" />

    <form method="POST" action="{{ route('portal.support.reply', $ticket->id) }}" enctype="multipart/form-data"
          class="space-y-3 rounded-card bg-surface p-4 border border-line shadow-card">
        @csrf
        <x-ui.field :label="__('Reply')">
            <x-ui.textarea name="body" rows="5" maxlength="10000" required>{{ old('body') }}</x-ui.textarea>
        </x-ui.field>
        <x-ui.field :label="__('Attachments (optional)')">
            <x-ui.input type="file" name="files[]" multiple accept=".pdf,.png,.jpg,.jpeg,.webp,.txt,.csv,.xml" />
            <p class="mt-1 text-xs text-muted">{{ __('Up to :count files, 5 MB each.', ['count' => $maxFiles]) }}</p>
        </x-ui.field>
        <x-ui.button>{{ __('Send reply') }}</x-ui.button>
    </form>

    @if ($ticket->status !== \App\Domains\Support\Enums\TicketStatus::Resolved)
        <form method="POST" action="{{ route('portal.support.resolve', $ticket->id) }}">
            @csrf
            <x-ui.button variant="secondary">{{ __('My problem is solved') }}</x-ui.button>
        </form>
    @endif
</div>
@endsection
