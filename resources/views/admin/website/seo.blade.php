@extends('layouts.admin')

@section('title', $definition->label)

@section('content')
{{--
    Website › Search and sharing: what a search result and a shared link
    say about the public site, English beside Arabic. Each language has its
    own address (/?lang=en, /?lang=ar), listed in sitemap.xml, so a search
    engine shows the one in the searcher's language.

    The picture is optional: a PNG, JPEG or WebP of at most 1 MB; 1200 by
    630 pixels is what most sites that show shared links draw.
--}}
<x-ui.page-header :eyebrow="__('Website')" :title="$definition->label" :description="$definition->description">
    <x-slot:actions>
        <x-ui.button variant="secondary" :href="route('sitemap')" target="_blank" rel="noopener">sitemap.xml</x-ui.button>
    </x-slot:actions>
</x-ui.page-header>

<form method="POST" action="{{ route('admin.website.seo.update') }}" enctype="multipart/form-data" class="space-y-6">
    @csrf

    <x-ui.card :title="__('Search results')" :description="__('The page title, and the description a search engine may show under it.')">
        <div class="space-y-5">
            @foreach (array_slice($definition->fields, 0, 2) as $field)
                @include('admin.website.sections.field', ['field' => $field, 'path' => $field->name, 'both' => $texts[$field->name]])
            @endforeach
        </div>
    </x-ui.card>

    <x-ui.card :title="__('Shared links')" :description="__('What a message or a social post shows when someone shares a link to the site.')">
        <div class="space-y-5">
            @foreach (array_slice($definition->fields, 2) as $field)
                @include('admin.website.sections.field', ['field' => $field, 'path' => $field->name, 'both' => $texts[$field->name]])
            @endforeach

            @if ($image)
                <div class="flex flex-wrap items-end gap-4">
                    <img src="{{ route('website.share-image', ['v' => $image['version']]) }}" alt="{{ __('The current picture') }}" class="h-24 rounded-tile border border-line object-cover">
                    <x-ui.checkbox name="remove_image" value="1">{{ __('Remove the picture') }}</x-ui.checkbox>
                </div>
            @endif
            <x-ui.field :label="$image ? __('Replace the picture') : __('Picture')" :hint="__('Optional. A PNG, JPEG or WebP of at most 1 MB; 1200 by 630 pixels suits most sites.')" error="image" optional>
                <x-ui.file name="image" accept="image/png,image/jpeg,image/webp" :max-kb="\App\Domains\Platform\Website\WebsiteSeo::IMAGE_MAX_KILOBYTES" />
            </x-ui.field>
        </div>
    </x-ui.card>

    <x-ui.button>{{ __('Save') }}</x-ui.button>
</form>
@endsection
