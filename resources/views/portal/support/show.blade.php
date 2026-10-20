@extends('layouts.portal')

@section('title', $ticket->reference())

@section('content')
<x-ui.page-header :title="$ticket->subject">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Requests'), 'href' => route('portal.support.index')], ['label' => $ticket->reference()]]" />
    </x-slot:breadcrumb>
    <x-slot:meta>
        @if ($ticket->stage)
            <x-ui.badge :tone="$ticket->stage->tone()" :label="$ticket->stage->label()" />
        @else
            <x-ui.badge :tone="$ticket->status->tone()" :label="$ticket->status->label()" />
        @endif
    </x-slot:meta>
    <x-slot:description><span dir="ltr">{{ $ticket->reference() }}</span> &middot; {{ $ticket->kind->staged() ? $ticket->kind->label() : $ticket->category->label() }} &middot; {{ __('opened :date', ['date' => "\u{2066}".$ticket->created_at->toDateString()."\u{2069}"]) }}</x-slot:description>
</x-ui.page-header>

<div class="max-w-3xl space-y-6">
    @if ($ticket->featureRequest)
        <dl class="grid grid-cols-1 gap-4 rounded-card bg-surface p-4 border border-line shadow-card sm:grid-cols-3">
            <div>
                <dt class="text-xs text-muted">{{ __('Category') }}</dt>
                <dd class="text-sm text-fg">{{ $categoryName }}</dd>
            </div>
            <div>
                <dt class="text-xs text-muted">{{ __('How much it matters') }}</dt>
                <dd class="text-sm text-fg">{{ $ticket->featureRequest->importance->label() }}</dd>
            </div>
            @if ($ticket->featureRequest->system_name)
                <div>
                    <dt class="text-xs text-muted">{{ __('The other system') }}</dt>
                    <dd class="text-sm text-fg">{{ $ticket->featureRequest->system_name }}</dd>
                </div>
            @endif
        </dl>
    @endif

    @if ($ticket->customization)
        <div class="space-y-2 rounded-card bg-surface p-4 border border-line shadow-card">
            <h2 class="text-sm font-semibold text-fg">{{ __('What we agreed') }}</h2>
            @if ($ticket->customization->description)
                <p class="whitespace-pre-line text-sm text-fg">{{ $ticket->customization->description }}</p>
            @endif
            <p class="text-sm text-muted">
                @if (! $ticket->customization->billable)
                    {{ __('This work is done at no charge.') }}
                @elseif ($ticket->customization->price !== null)
                    {{ __('Price, excluding VAT:') }} <x-ui.amount :value="$ticket->customization->price" :currency="$ticket->customization->currency" />.
                    {{ __('It is billed on your next invoice once it is released.') }}
                @else
                    {{ __('We will agree the work and its price with you here before we start.') }}
                @endif
            </p>
        </div>
    @endif

    <x-support-thread :ticket="$ticket" attachment-route="portal.support.attachment" />

    <form method="POST" action="{{ route('portal.support.reply', $ticket->id) }}" enctype="multipart/form-data"
          class="space-y-3 rounded-card bg-surface p-4 border border-line shadow-card">
        @csrf
        <x-ui.field :label="__('Reply')">
            <x-ui.textarea name="body" rows="5" maxlength="10000" required>{{ old('body') }}</x-ui.textarea>
        </x-ui.field>
        <x-ui.field :label="__('Attachments (optional)')">
            <x-ui.file name="files[]" multiple accept=".pdf,.png,.jpg,.jpeg,.webp,.txt,.csv,.xml" :max-kb="\App\Domains\Support\Services\TicketFiles::MAX_KILOBYTES" :max-files="$maxFiles" />
        </x-ui.field>
        <x-ui.button>{{ __('Send reply') }}</x-ui.button>
    </form>

    @if (! $ticket->kind->staged() && $ticket->status !== \App\Domains\Support\Enums\TicketStatus::Resolved)
        <form method="POST" action="{{ route('portal.support.resolve', $ticket->id) }}">
            @csrf
            <x-ui.button variant="secondary">{{ __('My problem is solved') }}</x-ui.button>
        </form>
    @endif
</div>
@endsection
