@extends('layouts.portal')

@section('title', $ticket->reference())

@section('content')
<x-ui.page-header :title="$ticket->subject">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Support'), 'href' => route('portal.support.index')], ['label' => $ticket->reference()]]" />
    </x-slot:breadcrumb>
    <x-slot:meta>
        <x-ui.badge :tone="$ticket->status->tone()" :label="$ticket->status->label()" />
    </x-slot:meta>
    <x-slot:description><span dir="ltr">{{ $ticket->reference() }}</span> &middot; {{ $ticket->category->label() }} &middot; {{ __('opened :date', ['date' => "\u{2066}".$ticket->created_at->toDateString()."\u{2069}"]) }}</x-slot:description>
</x-ui.page-header>

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
