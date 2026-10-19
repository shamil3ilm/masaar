@props(['value' => null, 'empty' => null, 'locale' => null])

{{--
    A country, chosen by name in either language and sent as its two-letter
    code (App\Support\CountryNames: ISO 3166-1 alpha-2, names from CLDR).

    It replaced a two-letter text box that took "sa", "KSA" and "Saudi" and
    let the server refuse all three. Searchable, as every long list is: type
    "emir", "الإمارات" or "AE". A code saved before the list existed and not
    on it is kept as an option of its own, so editing a record does not
    silently change its country.

    value  the code chosen
    empty  the words for no country, when a country is optional
    locale the language of the names, when it is not the page's (the
           style guide draws an Arabic specimen on an English page)
--}}
@php
    $chosen = strtoupper((string) $value);
    $locale ??= app()->getLocale();
    $names = \App\Support\CountryNames::options($locale);
    $other = $locale === 'ar' ? 'en' : 'ar';
@endphp
<x-ui.select searchable {{ $attributes }}>
    @if ($empty !== null)
        <option value="">{{ $empty }}</option>
    @endif
    @if ($chosen !== '' && ! array_key_exists($chosen, $names))
        <option value="{{ $chosen }}" selected>{{ $chosen }}</option>
    @endif
    @foreach ($names as $code => $name)
        <option value="{{ $code }}" data-search="{{ \App\Support\CountryNames::name($code, $other) }}" @selected($chosen === $code)>{{ $name }}</option>
    @endforeach
</x-ui.select>
