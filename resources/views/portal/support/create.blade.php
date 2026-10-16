@extends('layouts.portal')

@section('title', __('New ticket'))

@section('content')
<div class="mb-6">
    <p class="text-sm"><a href="{{ route('portal.support.index') }}" class="text-teal-700 dark:text-teal-300 hover:underline"><span class="inline-block rtl:rotate-180">&larr;</span> {{ __('Support') }}</a></p>
    <h2 class="mt-2 text-2xl font-bold text-gray-800 dark:text-gray-100">{{ __('New ticket') }}</h2>
    <p class="text-gray-500 dark:text-gray-400">{{ __('Tell us what happened. An invoice number and the message ZATCA gave help us answer faster.') }}</p>
</div>

<form method="POST" action="{{ route('portal.support.store') }}" enctype="multipart/form-data"
      class="max-w-3xl space-y-4 rounded-lg bg-white dark:bg-gray-900 p-6 shadow">
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
        <x-ui.input type="file" name="files[]" multiple accept=".pdf,.png,.jpg,.jpeg,.webp,.txt,.csv,.xml" />
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Up to :count files, 5 MB each: PDF, images, XML, CSV or text.', ['count' => $maxFiles]) }}</p>
    </x-ui.field>

    <x-ui.button>{{ __('Open ticket') }}</x-ui.button>
</form>
@endsection
