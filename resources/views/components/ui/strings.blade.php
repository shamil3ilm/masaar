{{--
    The words the page's scripts say, translated here so they are in the
    page's language (resources/js/strings.js reads them back by their
    English).

    A hidden element's attribute rather than an inline script: the content
    policy runs no inline script, and data is all this is. One per layout.
    Each is written out in __() so TranslationTest finds it and holds it to
    an Arabic line.
--}}
@php
    $strings = [
        // Searchable dropdowns (combobox.js)
        'Type to search' => __('Type to search'),
        'None' => __('None'),
        'Searching...' => __('Searching...'),
        'No matches' => __('No matches'),
        ':count results' => __(':count results'),
        'Could not search just now. The list shows what is already here.' => __('Could not search just now. The list shows what is already here.'),
    ];
@endphp
<div hidden data-ui-strings="{{ json_encode($strings, JSON_UNESCAPED_UNICODE) }}"></div>
