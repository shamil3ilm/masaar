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
        // Dates and times (date-field.js)
        'Choose a date' => __('Choose a date'),
        'YYYY-MM-DD' => __('YYYY-MM-DD'),
        'Hijri' => __('Hijri'),
        'Enter a date like 2026-10-14.' => __('Enter a date like 2026-10-14.'),
        'Previous month' => __('Previous month'),
        'Next month' => __('Next month'),
        'Today' => __('Today'),
        'Clear' => __('Clear'),
        'Choose a time' => __('Choose a time'),
        'HH:MM' => __('HH:MM'),
        'Enter a time like 13:30.' => __('Enter a time like 13:30.'),
        // File drop zones (file-field.js)
        'Drop files here, or choose them' => __('Drop files here, or choose them'),
        'Drop a file here, or choose one' => __('Drop a file here, or choose one'),
        ':count MB' => __(':count MB'),
        ':count KB' => __(':count KB'),
        'Choose at most :count files.' => __('Choose at most :count files.'),
        ':name is not a type this takes.' => __(':name is not a type this takes.'),
        ':name is larger than :size.' => __(':name is larger than :size.'),
        'Remove :name' => __('Remove :name'),
        'Uploading... :percent%' => __('Uploading... :percent%'),
        'The upload did not finish. Sending it again the usual way.' => __('The upload did not finish. Sending it again the usual way.'),
    ];
@endphp
<div hidden data-ui-strings="{{ json_encode($strings, JSON_UNESCAPED_UNICODE) }}"></div>
