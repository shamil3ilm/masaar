@extends('layouts.admin')

@section('title', 'Request categories')

@section('content')
<x-ui.page-header title="Request categories"
                  description="What a customer's feature request can be about. Each has a name in both languages; a category that names another system asks the customer which one. Retiring one stops it being offered; requests already in it keep it.">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => 'Settings', 'href' => route('admin.settings')], ['label' => 'Request categories']]" />
    </x-slot:breadcrumb>
</x-ui.page-header>

<div class="max-w-3xl space-y-4">
    @foreach ($categories as $category)
        <x-ui.section :title="$category->label.' · '.$category->labelAr" :description="$category->retired ? 'Retired: not offered, kept by the requests already in it.' : $category->key" closed>
            @admincan('platform.configure')
                <div class="mb-4 flex flex-wrap gap-2">
                    @foreach (['up' => 'Move up', 'down' => 'Move down'] as $direction => $label)
                        <form method="POST" action="{{ route('admin.request-categories.move', $category->key) }}">
                            @csrf
                            <input type="hidden" name="direction" value="{{ $direction }}">
                            <x-ui.button size="sm" variant="ghost" :disabled="($direction === 'up' && $loop->parent->first) || ($direction === 'down' && $loop->parent->last)">{{ $label }}</x-ui.button>
                        </form>
                    @endforeach
                    <form method="POST" action="{{ route('admin.request-categories.retire', $category->key) }}">
                        @csrf
                        <input type="hidden" name="retired" value="{{ $category->retired ? '0' : '1' }}">
                        <x-ui.button size="sm" variant="secondary">{{ $category->retired ? 'Offer again' : 'Retire' }}</x-ui.button>
                    </form>
                </div>

                <form method="POST" action="{{ route('admin.request-categories.update', $category->key) }}">
                    @csrf
                    @include('admin.partials.request-category-fields', ['category' => $category])
                    <x-ui.button class="mt-4">Save category</x-ui.button>
                </form>
            @else
                @include('admin.partials.request-category-fields', ['category' => $category])
            @endadmincan
        </x-ui.section>
    @endforeach

    @admincan('platform.configure')
        <x-ui.section title="Add a category">
            <form method="POST" action="{{ route('admin.request-categories.store') }}">
                @csrf
                <x-ui.field label="Key" hint="Lower-case letters, digits and underscores; it cannot change once added." error="key" class="mb-4">
                    <x-ui.input type="text" name="key" maxlength="40" required dir="ltr" value="{{ old('key') }}" />
                </x-ui.field>
                @include('admin.partials.request-category-fields', ['category' => null])
                <x-ui.button class="mt-4">Add category</x-ui.button>
            </form>
        </x-ui.section>
    @endadmincan
</div>
@endsection
