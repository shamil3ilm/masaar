@props(['value' => 0, 'currency' => null])
{{--
    A sum of money: two decimals, grouped thousands, then the currency code.

    Totals are decimal:2 casts, so Eloquent hands back strings - cast before
    formatting, or number_format() quietly takes the leading digits. Never
    write the currency in: two activity screens printed SAR after every
    total, which was wrong for any other currency and for every UAE
    organization.

    Left to right and in tabular figures, so a column of totals lines up and
    an Arabic page keeps the minus sign in front.

    value     string|float|null
    currency  the ISO code, or null in a column whose heading names it
--}}
<span dir="ltr" {{ $attributes->merge(['class' => 'tabular-nums']) }}>{{ number_format((float) ($value ?? 0), 2) }}@if ($currency) {{ $currency }}@endif</span>
