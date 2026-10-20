@extends('layouts.portal')

@section('title', __('New request'))

@section('content')
<x-ui.page-header :title="__('New request')" :description="__('Tell us what you need. For a problem, an invoice number and the message the tax authority gave help us answer faster.')">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Requests'), 'href' => route('portal.support.index')], ['label' => __('New request')]]" />
    </x-slot:breadcrumb>
</x-ui.page-header>

<div class="max-w-3xl space-y-4">
    {{-- The three kinds, as links: the form below follows the one chosen. --}}
    <nav class="grid grid-cols-1 gap-3 sm:grid-cols-3" aria-label="{{ __('Kind of request') }}">
        @foreach ($kinds as $option)
            <a href="{{ route('portal.support.create', ['kind' => $option->value]) }}"
               @if ($option === $kind) aria-current="page" @endif
               @class([
                   'block rounded-card border p-4 shadow-card hover:bg-sunken',
                   'border-accent-line bg-accent-soft' => $option === $kind,
                   'border-line bg-surface' => $option !== $kind,
               ])>
                <span class="block text-sm font-semibold text-fg">{{ $option->label() }}</span>
                <span class="mt-1 block text-xs text-muted">{{ $option->describe() }}</span>
            </a>
        @endforeach
    </nav>

    <form method="POST" action="{{ route('portal.support.store') }}" enctype="multipart/form-data"
          class="space-y-4 rounded-card bg-surface p-6 border border-line shadow-card">
        @csrf
        <input type="hidden" name="kind" value="{{ $kind->value }}">

        <x-ui.field :label="$kind === \App\Domains\Support\Enums\TicketKind::Support ? __('Subject') : __('What you would like, in a line')" error="subject">
            <x-ui.input type="text" name="subject" maxlength="160" required value="{{ old('subject') }}" />
        </x-ui.field>

        @if ($kind === \App\Domains\Support\Enums\TicketKind::Support)
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.field :label="__('About')">
                    <x-ui.select name="category">
                        @foreach ($categories as $category)
                            <option value="{{ $category->value }}" @selected(old('category') === $category->value)>{{ $category->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field :label="__('Priority')">
                    <x-ui.select name="priority">
                        @foreach ($priorities as $priority)
                            <option value="{{ $priority->value }}" @selected(old('priority') === $priority->value)>{{ $priority->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </div>
        @elseif ($kind === \App\Domains\Support\Enums\TicketKind::Feature)
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.field :label="__('Category')" error="feature_category">
                    <x-ui.select name="feature_category">
                        @foreach ($featureCategories as $category)
                            <option value="{{ $category->key }}" @selected(old('feature_category') === $category->key)>{{ $category->name() }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field :label="__('How much it matters')" error="importance">
                    <x-ui.select name="importance">
                        @foreach ($importances as $importance)
                            <option value="{{ $importance->value }}" @selected(old('importance') === $importance->value)>{{ $importance->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </div>
            <x-ui.field :label="__('The other system')" :hint="__('For an integration: the ERP, store, bank or other system it would connect with.')" error="system_name" optional>
                <x-ui.input type="text" name="system_name" maxlength="120" value="{{ old('system_name') }}" />
            </x-ui.field>
        @else
            <x-ui.alert tone="info">{{ __('We read what you need, agree the work and its price with you, and only then start. Nothing is charged before you agree.') }}</x-ui.alert>
        @endif

        <x-ui.field :label="$kind === \App\Domains\Support\Enums\TicketKind::Support ? __('What happened') : __('Describe what you need and why')" error="body">
            <x-ui.textarea name="body" rows="8" maxlength="10000" required>{{ old('body') }}</x-ui.textarea>
        </x-ui.field>

        <x-ui.field :label="__('Attachments (optional)')">
            <x-ui.file name="files[]" multiple accept=".pdf,.png,.jpg,.jpeg,.webp,.txt,.csv,.xml" :max-kb="\App\Domains\Support\Services\TicketFiles::MAX_KILOBYTES" :max-files="$maxFiles" />
        </x-ui.field>

        <x-ui.button>{{ $kind === \App\Domains\Support\Enums\TicketKind::Support ? __('Open ticket') : __('Send request') }}</x-ui.button>
    </form>
</div>
@endsection
