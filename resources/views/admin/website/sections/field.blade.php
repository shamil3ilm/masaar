{{--
    One section field, English beside Arabic, each in its own language and
    direction. $path is the field's place in the form in dots (items.0.title),
    which is also where its error is; the input's name is the same in
    brackets. A field in a list row is not required in the browser: emptying
    a row is how it is removed.
--}}
@php
    $segments = explode('.', $path);
    $base = array_shift($segments).implode('', array_map(fn (string $segment): string => '['.$segment.']', $segments));
    $inList = str_contains($path, '.');
    $optional ??= false;
@endphp
<div class="grid gap-4 md:grid-cols-2">
    @foreach (['en' => __('English'), 'ar' => __('Arabic')] as $locale => $language)
        <x-ui.field :label="$field->label.' - '.$language" :error="$path.'.'.$locale" :optional="$optional" lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
            @if ($field->paragraph)
                <x-ui.textarea name="{{ $base }}[{{ $locale }}]" rows="3" maxlength="{{ $field->max() }}" :required="! $inList">{{ old($path.'.'.$locale, $both[$locale] ?? '') }}</x-ui.textarea>
            @else
                <x-ui.input name="{{ $base }}[{{ $locale }}]" maxlength="{{ $field->max() }}" :required="! $inList" value="{{ old($path.'.'.$locale, $both[$locale] ?? '') }}" />
            @endif
        </x-ui.field>
    @endforeach
</div>
