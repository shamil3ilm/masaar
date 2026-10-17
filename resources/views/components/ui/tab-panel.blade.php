@props(['id', 'name', 'selected' => false])

{{--
    One panel of an <x-ui.tabs>: id is the tabs' id, name its key there.
    Hidden unless selected; resources/js/ui.js shows the one chosen.
--}}
<div role="tabpanel" id="{{ $id }}-panel-{{ $name }}" aria-labelledby="{{ $id }}-tab-{{ $name }}" tabindex="0"
     @unless ($selected) hidden @endunless {{ $attributes->merge(['class' => 'focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-focus rounded-tile']) }}>
    {{ $slot }}
</div>
