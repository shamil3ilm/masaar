{{--
    The VAT return draft as a PDF (BooksPdf): the same sections and boxes as
    the page, for the file or the accountant's records.

    @param \App\Domains\Compliance\Engine\DTOs\VatReturnDraft $draft
    @param \App\Domains\Organization\Models\Organization $organization
    @param bool $rtl
--}}
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 9pt; }
        h1 { font-size: 14pt; margin: 0 0 4pt; }
        h2 { font-size: 11pt; margin: 12pt 0 4pt; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 0.5pt solid gray; padding: 3pt; text-align: {{ $rtl ? 'right' : 'left' }}; }
        .n { text-align: {{ $rtl ? 'left' : 'right' }}; direction: ltr; }
        .t td { font-weight: bold; }
    </style>
</head>
<body>
    <h1>{{ __($draft->title) }} - {{ $organization->name }}</h1>
    <p>{{ __('From :from to :to, in :currency.', ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'currency' => $draft->currency]) }}</p>
    @foreach ($draft->problems as $problem)
        <p>{{ $problem }}</p>
    @endforeach
    @foreach ($draft->sections as $section => $boxes)
        <h2>{{ __($section) }}</h2>
        <table>
            <tr><th scope="col">{{ __('Box') }}</th><th scope="col">{{ __('Description') }}</th><th scope="col" class="n">{{ __('Amount') }}</th><th scope="col" class="n">{{ __('Adjustment') }}</th><th scope="col" class="n">{{ __('VAT') }}</th></tr>
            @foreach ($boxes as $box)
                <tr @class(['t' => ! $box->traced])>
                    <td>{{ $box->key }}</td>
                    <td>{{ __($box->label) }}</td>
                    @foreach (['amount', 'adjustment', 'vat'] as $column)
                        <td class="n">{{ $box->{$column} === null ? '-' : \App\Domains\Books\Services\Purchases::shown($box->{$column}) }}</td>
                    @endforeach
                </tr>
            @endforeach
        </table>
    @endforeach
    @foreach ($draft->notes as $note)
        <p>{{ $note }}</p>
    @endforeach
</body>
</html>
