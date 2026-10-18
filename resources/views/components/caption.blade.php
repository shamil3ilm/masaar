{{--
    A field caption in both languages.

    Article 66(2) of the VAT Implementing Regulations requires an invoice to be
    issued in Arabic, and ZATCA's Guideline for Tax Invoicing and Records (v3,
    May 2026) section 4.2.1 puts it plainly: "All fields of the tax invoice
    must be completed in the Arabic language. The information stated in Arabic
    shall be considered the approved and conclusive information, and any other
    language shall be treated as a translation only."

    The same section exempts the data this application stores - proper names,
    numerals, currency codes, dates and invoice numbers may be written in Latin
    characters. So what the requirement actually reaches is the furniture: the
    document title and the captions on every field. Those were English only.

    The Arabic wording is taken from ZATCA's own illustrative invoices in the
    annex of that guideline rather than translated here, so the words on a
    Masaar invoice are the words the authority prints on its examples.

    English above, Arabic below, which is how the annex lays out a column
    heading - or, arabicFirst, the Arabic above and the English below it, as
    a Saudi invoice prints unless its organization chose English first
    (PrintPreferences::arabicFirst). Each is tagged with its own language and
    direction so a screen reader announces the Arabic as Arabic and the
    browser shapes it correctly.
--}}
@props(['en', 'ar', 'arabicFirst' => false])

<span {{ $attributes->merge(['class' => 'block']) }}>
    @if ($arabicFirst)
        <span class="block" dir="rtl" lang="ar">{{ $ar }}</span>
        <span class="block font-normal text-subtle normal-case" dir="ltr" lang="en">{{ $en }}</span>
    @else
        <span class="block">{{ $en }}</span>
        <span class="block font-normal text-subtle normal-case" dir="rtl" lang="ar">{{ $ar }}</span>
    @endif
</span>
