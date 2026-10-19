{{--
    The page's theme: its icons (x-app-icons), and the stylesheet that sets
    its colours.

    After the application's own stylesheet, so the colour variables it sets
    are the ones that apply. A same-origin stylesheet rather than inline
    style, which the content policy refuses everywhere.

    $theme comes from ThemeComposer.
--}}
<x-app-icons :theme="$theme" />
<link rel="stylesheet" href="{{ $theme->stylesheetUrl }}">
