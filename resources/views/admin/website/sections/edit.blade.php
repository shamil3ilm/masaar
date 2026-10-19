@extends('layouts.admin')

@section('title', $definition->label)

@section('content')
{{--
    One home page section's words, English beside Arabic. Every field is
    needed in both. Plain text only: what is typed is shown as typed, with
    line breaks kept and **double asterisks** for bold - never as markup.
    No field may name a price.

    A list shows its items and, below a free list's last, spare empty rows
    to add more; a row emptied in both languages is removed on save. A fixed
    list (the three asks) keeps its count.

    "Reset to default" is a form of its own, after this one, and asks first.
--}}
@php
    $spare = 2;
@endphp

<x-ui.page-header :eyebrow="__('Home page')" :title="$definition->label" :description="$definition->description">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[
            ['label' => __('Home page'), 'href' => route('admin.website.sections.index')],
            ['label' => $definition->label],
        ]" />
    </x-slot:breadcrumb>
    <x-slot:meta>
        <x-ui.badge :tone="$edited ? 'info' : 'neutral'" :label="$edited ? __('Edited') : __('Default')" />
    </x-slot:meta>
    <x-slot:actions>
        <x-ui.button variant="secondary" :href="url('/').'#'.$definition->key" target="_blank" rel="noopener">{{ __('View on the home page') }}</x-ui.button>
    </x-slot:actions>
</x-ui.page-header>

<form method="POST" action="{{ route('admin.website.sections.update', $definition->key) }}" class="space-y-6" data-section-form>
    @csrf

    <x-ui.card :title="__('Text')" :description="__('Plain text, in English and Arabic. Line breaks are kept, **words between double asterisks** are bold, and :app is replaced by the product\'s name.', ['app' => ':app'])">
        <div class="space-y-5">
            @foreach ($definition->fields as $field)
                @include('admin.website.sections.field', ['field' => $field, 'path' => $field->name, 'both' => $texts[$field->name] ?? ['en' => '', 'ar' => '']])
            @endforeach
        </div>
    </x-ui.card>

    @foreach ($definition->lists as $list)
        @php
            $items = array_values((array) old($list->name, $texts[$list->name] ?? []));
            $rows = $list->fixed ? $list->max : min($list->max, count($items) + $spare);
        @endphp
        <x-ui.section :title="$list->label"
            :description="$list->fixed ? __('Each keeps its place; only the words change.') : __('From :min to :max. Fill in a spare row to add one; empty a row in both languages to remove it.', ['min' => $list->min, 'max' => $list->max])">
            <ol class="space-y-6">
                @for ($row = 0; $row < $rows; $row++)
                    <li class="space-y-4 border-b border-line pb-6 last:border-b-0 last:pb-0" data-list-row>
                        <p class="text-sm font-semibold text-fg">
                            {{ $row < count($items) ? __('Item :number', ['number' => $row + 1]) : __('New item') }}
                        </p>
                        @foreach ($list->fields as $field)
                            @include('admin.website.sections.field', [
                                'field' => $field,
                                'path' => $list->name.'.'.$row.'.'.$field->name,
                                'both' => (array) ($items[$row][$field->name] ?? ['en' => '', 'ar' => '']),
                                'optional' => $row >= count($items),
                            ])
                        @endforeach
                    </li>
                @endfor
            </ol>
            @error($list->name)
                <x-ui.alert tone="danger" class="mt-4">{{ $message }}</x-ui.alert>
            @enderror
        </x-ui.section>
    @endforeach

    <div class="flex flex-wrap gap-3">
        <x-ui.button>{{ __('Save section') }}</x-ui.button>
        <x-ui.button variant="ghost" :href="route('admin.website.sections.index')">{{ __('Cancel') }}</x-ui.button>
    </div>
</form>

@if ($edited)
    <form method="POST" action="{{ route('admin.website.sections.reset', $definition->key) }}" class="mt-8 border-t border-line pt-6"
          data-confirm-tone="danger" data-confirm-action="{{ __('Reset to default') }}"
          data-confirm="{{ __('Reset :section to its default text?', ['section' => $definition->label]) }}"
          data-confirm-detail="{{ __('Its words in both languages go back to what the page shipped with, and are live at once. Whether it is shown, and its place, stay.') }}">
        @csrf
        <x-ui.button variant="danger">{{ __('Reset to default') }}</x-ui.button>
    </form>
@endif
@endsection
