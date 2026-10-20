{{--
    One sample in each language, a tab each. $id is unique on the page,
    $samples language => code, $languages language => its name.
--}}
<x-ui.tabs :id="$id" :label="__('Language')" :tabs="array_intersect_key($languages, $samples)">
    @foreach ($samples as $language => $code)
        <x-ui.tab-panel :id="$id" :name="$language" :selected="$loop->first">
            <pre class="overflow-x-auto rounded-tile border border-line bg-sunken p-4 text-xs leading-relaxed text-fg" dir="ltr" data-code-sample="{{ $language }}"><code>{{ $code }}</code></pre>
        </x-ui.tab-panel>
    @endforeach
</x-ui.tabs>
