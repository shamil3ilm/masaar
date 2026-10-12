{{--
    One amount, in the invoice's own currency.

    Totals are decimal:2 casts, so Eloquent hands back strings - cast before
    formatting or number_format() quietly takes the leading digits. And never
    hardcode SAR: portal/submissions.blade.php does, which is wrong for any
    non-SAR invoice and for every UAE organization.

    @param $amount    string|float|null
    @param $currency  string
--}}
{{ number_format((float) ($amount ?? 0), 2) }} {{ $currency }}
