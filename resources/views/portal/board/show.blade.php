@extends('layouts.portal')

@php($item = $standing->item)

@section('title', $item->localTitle())

@section('content')
<x-ui.page-header :title="$item->localTitle()">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Requests'), 'href' => route('portal.support.index')], ['label' => __('Feature board'), 'href' => route('portal.board.index')], ['label' => $item->reference()]]" />
    </x-slot:breadcrumb>
    <x-slot:meta>
        <x-ui.badge :tone="$item->stage->tone()" :label="$item->stage->label()" />
    </x-slot:meta>
    <x-slot:description><span dir="ltr">{{ $item->reference() }}</span> &middot; {{ $standing->categoryName }} &middot; {{ __('Companies asking: :count', ['count' => $standing->demand]) }}</x-slot:description>
</x-ui.page-header>

<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <div class="rounded-card border border-line bg-surface p-4 shadow-card">
            <p class="whitespace-pre-line text-sm text-fg">{{ $item->localSummary() }}</p>
            @if ($item->released_in)
                <p class="mt-3 text-sm text-muted">
                    {{ __('Released in :release.', ['release' => $item->released_in]) }}
                    @if ($item->release_url)
                        <a href="{{ $item->release_url }}" class="text-accent hover:underline" rel="noopener noreferrer" target="_blank">{{ __('What is new') }}</a>
                    @endif
                </p>
            @endif
        </div>

        <section aria-labelledby="board-updates">
            <h2 id="board-updates" class="mb-3 text-sm font-semibold text-fg">{{ __('Updates') }}</h2>
            <ol class="space-y-3">
                @forelse ($item->updates as $update)
                    <li class="rounded-lg border border-line bg-surface p-4">
                        <div class="mb-1 flex flex-wrap items-center gap-2 text-xs text-muted">
                            @if ($update->stage)
                                <span>{{ __('Moved to') }}</span>
                                <x-ui.badge :tone="$update->stage->tone()" :label="$update->stage->label()" />
                            @endif
                            <x-ui.date :value="$update->created_at" />
                        </div>
                        @if ($update->localNote())
                            <p class="whitespace-pre-line text-sm text-fg">{{ $update->localNote() }}</p>
                        @endif
                    </li>
                @empty
                    <li class="text-sm text-muted">{{ __('No updates yet.') }}</li>
                @endforelse
            </ol>
        </section>
    </div>

    <div class="space-y-4">
        <div class="space-y-3 rounded-card border border-line bg-surface p-4 shadow-card">
            @if ($standing->askedByUs)
                <p class="text-sm text-fg">{{ __('Your company asked for this. Its own request, and the conversation about it, are in Requests.') }}</p>
            @else
                <form method="POST" action="{{ route('portal.board.back', $item->id) }}">
                    @csrf
                    <input type="hidden" name="back" value="{{ $standing->backedByUs ? '0' : '1' }}">
                    <x-ui.button :variant="$standing->backedByUs ? 'secondary' : 'primary'" class="w-full">
                        {{ $standing->backedByUs ? __('Withdraw our backing') : __('We need this too') }}
                    </x-ui.button>
                </form>
            @endif
            <form method="POST" action="{{ route('portal.board.follow', $item->id) }}">
                @csrf
                <input type="hidden" name="follow" value="{{ $standing->following ? '0' : '1' }}">
                <x-ui.button variant="secondary" class="w-full">{{ $standing->following ? __('Stop following') : __('Follow') }}</x-ui.button>
            </form>
            <p class="text-xs text-subtle">{{ __('Following, you are emailed when it moves on. Other companies never see that yours backs or follows it.') }}</p>
        </div>
    </div>
</div>
@endsection
