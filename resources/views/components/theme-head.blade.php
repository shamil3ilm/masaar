{{--
    The page's theme: its favicon, and the stylesheet that sets its colours.

    After the application's own stylesheet, so the colour variables it sets
    are the ones that apply. A same-origin stylesheet rather than inline
    style, which the content policy refuses everywhere.

    $theme comes from ThemeComposer.
--}}
<link rel="icon" href="{{ $theme->faviconUrl }}" type="{{ $theme->faviconType }}">
<link rel="stylesheet" href="{{ $theme->stylesheetUrl }}">
