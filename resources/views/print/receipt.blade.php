{{--
    An issued invoice on a till's roll: one column, as narrow as the roll.

    Drawn from the country's Receipt (Presentation::receipt()), which decides
    what is on it; this only lays it out. The same markup serves the browser's
    print view and the PDF renderer, styled by resources/css/print/receipt.css
    in both, so it keeps to what that renderer understands: tables for
    anything side by side, and classes rather than inline style, which the
    browser's content policy refuses.

    Black on white with no fills: a thermal head prints a dot or none, and a
    grey becomes a pattern of dots that blurs the text on top of it.

    @param \App\Domains\Compliance\Engine\DTOs\Receipt $receipt
    @param string $qr      the country's QR code as markup (an <svg> or an <img>), or ''
    @param string|null $logo  the seller's logo in black and white, as a data URI
    @param array|null $extraQr  the organization's own QR: label, and image markup

    $receipt->arabicFirst lays it out right to left: the Arabic of every
    caption leads - above the English, or at the right where the two share a
    line - and amounts sit at the left. The table cells keep their order and
    swap their contents, which both renderers draw alike.
--}}
@php
    // Right-aligned and right-to-left when it starts in Arabic: a name or a
    // description in either language reads from its own side.
    $dir = fn (?string $text): string => preg_match('/^[^\p{L}]*\p{Arabic}/u', (string) $text) === 1 ? 'rtl' : 'ltr';
@endphp
@php($rtl = $receipt->arabicFirst)
<div class="receipt" lang="{{ $rtl ? 'ar' : 'en' }}" @if ($rtl) data-arabic-first @endif>
    @if ($logo)
        <div class="r-center"><img src="{{ $logo }}" alt="" class="r-logo"></div>
    @endif
    @if ($rtl)
        <div class="r-title" dir="rtl" lang="ar">{{ $receipt->title->ar }}</div>
        <div class="r-title" lang="en">{{ $receipt->title->en }}</div>
    @else
        <div class="r-title">{{ $receipt->title->en }}</div>
        <div class="r-title" dir="rtl" lang="ar">{{ $receipt->title->ar }}</div>
    @endif
    {{-- The organization's own header, from its invoice template. --}}
    @foreach ($receipt->header as $line)
        <div class="r-small r-center" dir="{{ $dir($line) }}">{{ $line }}</div>
    @endforeach

    @foreach ($receipt->sections as $section)
        <div class="r-rule"></div>
        @if ($section->heading)
            <table class="r-row"><tr>
                <td class="r-start r-strong">{{ $section->heading->en }}</td>
                <td class="r-end r-strong" dir="rtl" lang="ar">{{ $section->heading->ar }}</td>
            </tr></table>
        @endif
        @if ($section->name)
            <div class="r-name r-{{ $dir($section->name) }}" dir="{{ $dir($section->name) }}">{{ $section->name }}</div>
        @endif
        @foreach ($section->rows as $row)
            <table class="r-row"><tr>
                <td class="r-start r-label">{{ $row->label->en }}</td>
                <td class="r-end r-label" dir="rtl" lang="ar">{{ $row->label->ar }}</td>
            </tr><tr>
                <td colspan="2" class="{{ $rtl ? 'r-value-end' : 'r-value' }}{{ $row->strong ? ' r-strong' : '' }}" dir="ltr">{{ $row->value }}</td>
            </tr></table>
        @endforeach
        @foreach ($section->lines as $line)
            <div class="r-text r-{{ $dir($line) }}" dir="{{ $dir($line) }}">{{ $line }}</div>
        @endforeach
    @endforeach

    <div class="r-rule"></div>
    <table class="r-row r-head"><tr>
        @if ($rtl)
            <td class="r-start r-label r-amount"><span dir="rtl" lang="ar">{{ $receipt->totalLabel->ar }}</span><br>{{ $receipt->totalLabel->en }}</td>
            <td class="r-end r-label"><span dir="rtl" lang="ar">{{ $receipt->quantityLabel->ar }} &times; {{ $receipt->unitPriceLabel->ar }}</span><br>{{ $receipt->quantityLabel->en }} &times; {{ $receipt->unitPriceLabel->en }}</td>
        @else
            <td class="r-start r-label">{{ $receipt->quantityLabel->en }} &times; {{ $receipt->unitPriceLabel->en }}@if ($receipt->quantityLabel->ar !== '')<br><span dir="rtl" lang="ar">{{ $receipt->quantityLabel->ar }} &times; {{ $receipt->unitPriceLabel->ar }}</span>@endif</td>
            <td class="r-end r-label r-amount">{{ $receipt->totalLabel->en }}<br><span dir="rtl" lang="ar">{{ $receipt->totalLabel->ar }}</span></td>
        @endif
    </tr></table>

    @foreach ($receipt->items as $item)
        <div class="r-item">
            <div class="r-description r-{{ $dir($item->description) }}" dir="{{ $dir($item->description) }}">{{ $item->description }}</div>
            <table class="r-row"><tr>
                @if ($rtl)
                    <td class="r-start r-amount" dir="ltr">{{ $item->total }} =</td>
                    <td class="r-end" dir="ltr">{{ $item->quantity }} &times; {{ $item->unitPrice }}</td>
                @else
                    <td class="r-start" dir="ltr">{{ $item->quantity }} &times; {{ $item->unitPrice }}</td>
                    <td class="r-end r-amount" dir="ltr">= {{ $item->total }}</td>
                @endif
            </tr></table>
            @if ($item->discount)
                <table class="r-row r-small"><tr>
                    <td class="r-start">Discount</td>
                    <td class="r-end" dir="rtl" lang="ar">الخصم</td>
                    <td class="r-end r-amount" dir="ltr">{{ $item->discount }}</td>
                </tr></table>
            @endif
            @foreach ($item->details as $detail)
                {{-- Each language in a cell of its own: a renderer laying
                     out English and Arabic in one cell may order them by
                     the Arabic's direction. --}}
                <table class="r-row r-small"><tr>
                    <td class="r-start">{{ $detail->label->en }}</td>
                    <td class="r-end" dir="rtl" lang="ar">{{ $detail->label->ar }}</td>
                    <td class="r-end r-amount" dir="ltr">{{ $detail->value }}</td>
                </tr></table>
            @endforeach
            @foreach ($item->notes as $note)
                <div class="r-small r-{{ $dir($note) }}" dir="{{ $dir($note) }}">{{ $note }}</div>
            @endforeach
        </div>
    @endforeach

    @if ($receipt->itemsNote && $rtl)
        <div class="r-small r-end" dir="rtl" lang="ar">{{ $receipt->itemsNote->ar }}</div>
        <div class="r-small r-end">{{ $receipt->itemsNote->en }}</div>
    @elseif ($receipt->itemsNote)
        <div class="r-small r-end">{{ $receipt->itemsNote->en }}</div>
        <div class="r-small r-end" dir="rtl" lang="ar">{{ $receipt->itemsNote->ar }}</div>
    @endif

    <div class="r-rule"></div>
    @foreach ($receipt->totals as $row)
        <table class="r-row{{ $row->strong ? ' r-grand' : '' }}"><tr>
            @if ($rtl)
                <td class="r-start r-amount" dir="ltr">{{ $row->value }}</td>
                <td class="r-end r-label"><span dir="rtl" lang="ar">{{ $row->label->ar }}</span><br>{{ $row->label->en }}</td>
            @else
                <td class="r-start r-label">{{ $row->label->en }}<br><span dir="rtl" lang="ar">{{ $row->label->ar }}</span></td>
                <td class="r-end r-amount" dir="ltr">{{ $row->value }}</td>
            @endif
        </tr></table>
    @endforeach

    @if ($receipt->notes)
        <div class="r-rule"></div>
        <table class="r-row"><tr>
            <td class="r-start r-label">{{ $receipt->notes->label->en }}</td>
            <td class="r-end r-label" dir="rtl" lang="ar">{{ $receipt->notes->label->ar }}</td>
        </tr></table>
        <div class="r-text r-{{ $dir($receipt->notes->value) }}" dir="{{ $dir($receipt->notes->value) }}">{{ $receipt->notes->value }}</div>
    @endif

    {{-- The template's terms, bank details and closing note. --}}
    @if ($receipt->closing !== [])
        <div class="r-rule"></div>
        @foreach ($receipt->closing as $line)
            <div class="r-small r-{{ $dir($line) }}" dir="{{ $dir($line) }}">{{ $line }}</div>
        @endforeach
    @endif

    @if ($qr !== '')
        <div class="r-rule"></div>
        {{-- Unescaped: markup QrCodeRenderer drew from the stored payload,
             not anything a person typed. --}}
        <div class="r-center" data-print-qr>{!! $qr !!}</div>
    @endif

    {{-- The organization's own QR, apart from the country's and labelled:
         nothing is added to the QR the authority defines. --}}
    @if (! empty($extraQr))
        <div class="r-rule"></div>
        <div class="r-center r-strong r-small">{{ $extraQr['label'] }}</div>
        <div class="r-center" data-print-extra-qr>{!! $extraQr['image'] !!}</div>
    @endif

    @foreach ($receipt->footer as $line)
        <div class="r-footer">{{ $line }}</div>
    @endforeach
</div>
