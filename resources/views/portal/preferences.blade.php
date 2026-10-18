@extends('layouts.portal')

@section('title', __('Preferences'))

@section('content')
<x-ui.page-header :title="__('Preferences')"
                  :description="__('Your own choices. Each one says what is in effect for you and where it comes from; reset one and your organization\'s setting applies again.')" />

<x-ui.card class="max-w-3xl" flush>
    @foreach ($rows as $resolved)
        <x-customization :resolved="$resolved" :action="route('portal.preferences.update', $resolved->definition->key)" />
    @endforeach
</x-ui.card>
@endsection
