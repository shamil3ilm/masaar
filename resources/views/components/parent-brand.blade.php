@props(['as' => 'wordmark'])

{{--
    "just let me know." - the business behind Masaar, signed quietly on the
    public pages (and only there, when Settings' site.easter_eggs is on).

    Its rules: the name is always lowercase and always Latin, in Arabic too;
    the coral period is its signature; it is never recoloured, stretched or
    given effects. Drawn here as styled text for now, because the brand's
    own files set the wordmark as live text from a web font this site's
    content policy refuses. When the outlined official SVG arrives, the
    wordmark branch below is the one place it goes.

    as  'wordmark'  the name, with its coral period
        'period'    the period alone, linking to the brand's site - the last
                    character of the public footer's legal line
--}}
@if ($as === 'period')
    <a href="{{ \App\Domains\Platform\Services\PublicSite::PARENT_BRAND_URL }}" rel="noopener" aria-label="just let me know."
       {{ $attributes->merge(['class' => 'inline-flex min-h-11 min-w-5 items-center justify-center rounded font-semibold text-jlmk-coral hover:opacity-80 focus-visible:outline-2 focus-visible:outline-focus']) }}>.</a>
@else
    <span lang="en" dir="ltr" {{ $attributes->merge(['class' => 'whitespace-nowrap font-semibold lowercase tracking-tight']) }}>just let me know<span class="text-jlmk-coral">.</span></span>
@endif
