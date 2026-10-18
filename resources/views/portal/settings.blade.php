@extends('layouts.portal')

@section('title', __('Settings'))

@section('content')
<x-ui.page-header :title="__('Settings')"
                  :description="__('What everyone in this organization works with, unless they choose their own. Each setting says what is in effect and where it comes from; reset one and the platform\'s default applies again.')" />

<x-ui.card class="max-w-3xl" flush>
    @foreach ($rows as $resolved)
        <x-customization :resolved="$resolved" :action="route('portal.settings.update', $resolved->definition->key)" />
    @endforeach
</x-ui.card>
@endsection
