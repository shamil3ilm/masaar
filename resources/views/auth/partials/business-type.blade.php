{{--
    Sign-up's business type: what the business mostly is, from the types its
    country serves (BusinessTypes::offered, the country's Sectors). Optional,
    and only defaults: it never limits anything, and the company page
    changes it.

    One select per country offered, as the country sections are: the ones
    not chosen are hidden and disabled, so only the chosen country's is
    sent (resources/js/signup-country.js).

    @param list<array{code: string, businessTypes: list<\App\Domains\Organization\DTOs\BusinessType>}> $sections
    @param string $country  the country chosen
    @param bool   $choice   whether the form asks which country
--}}
@foreach ($sections as $section)
<fieldset class="space-y-2"
          @if ($choice) :hidden="country !== '{{ $section['code'] }}'" :disabled="country !== '{{ $section['code'] }}'" @endif
          @if ($country !== $section['code']) hidden disabled @endif>
    <x-ui.field :label="__('What your business mostly is')" error="business_type" optional
                :hint="__('Sets the defaults you start with, such as receipts on a till roll. It never limits what you can do, and you can change it on your company page.')">
        <x-ui.select name="business_type">
            <option value="">{{ __('Not set') }}</option>
            @foreach ($section['businessTypes'] as $type)
                <option value="{{ $type->key }}" @selected(old('business_type') === $type->key)>{{ $type->name() }}</option>
            @endforeach
        </x-ui.select>
    </x-ui.field>
    @foreach (collect($section['businessTypes'])->filter(fn ($type) => $type->note !== null)->groupBy('note') as $note => $types)
        <p class="text-xs text-subtle">{{ $types->map(fn ($type) => $type->name())->join(', ') }}: {{ $note }}</p>
    @endforeach
</fieldset>
@endforeach
