@extends('layouts.portal')

@section('title', __('New ticket'))

@section('content')
<x-ui.page-header :title="__('New ticket')" :description="__('Tell us what happened. An invoice number and the message ZATCA gave help us answer faster.')">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Support'), 'href' => route('portal.support.index')], ['label' => __('New ticket')]]" />
    </x-slot:breadcrumb>
</x-ui.page-header>

<form method="POST" action="{{ route('portal.support.store') }}" enctype="multipart/form-data"
      class="max-w-3xl space-y-4 rounded-card bg-surface p-6 border border-line shadow-card">
    @csrf
    <x-ui.field :label="__('Subject')">
        <x-ui.input type="text" name="subject" maxlength="160" required value="{{ old('subject') }}" />
    </x-ui.field>

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

    <x-ui.field :label="__('What happened')">
        <x-ui.textarea name="body" rows="8" maxlength="10000" required>{{ old('body') }}</x-ui.textarea>
    </x-ui.field>

    <x-ui.field :label="__('Attachments (optional)')">
        <x-ui.file name="files[]" multiple accept=".pdf,.png,.jpg,.jpeg,.webp,.txt,.csv,.xml" :max-kb="\App\Domains\Support\Services\TicketFiles::MAX_KILOBYTES" :max-files="$maxFiles" />
    </x-ui.field>

    <x-ui.button>{{ __('Open ticket') }}</x-ui.button>
</form>
@endsection
