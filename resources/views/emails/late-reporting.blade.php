{{--
    Simplified invoices not reported within twenty-four hours of issue, or
    since reported, and what ZATCA's Detailed Guidelines for E-Invoicing ask
    of the seller (p48). Arabic first, then English, whatever the reader's
    language (LateReportingMail).

    @param string $organizationName
    @param list<array{number: string, issued_at: string}> $documents
    @param bool $resolved
    @param string $url
--}}
@extends('emails.layout')

@section('title', $resolved ? 'Late invoices now reported' : 'Invoices not reported within 24 hours')

@section('content')
    <div dir="rtl" lang="ar" style="text-align:right;">
        @if ($resolved)
            <h1 style="margin:0 0 12px;font-size:20px;line-height:1.4;font-weight:600;">تم الإبلاغ عن الفواتير المتأخرة لدى {{ $organizationName }}</h1>
            <p style="margin:0 0 12px;">وصلت الفواتير المبسطة أدناه إلى هيئة الزكاة والضريبة والجمارك بعد انقضاء مهلة الأربع والعشرين ساعة. تنص الإرشادات التفصيلية للفوترة الإلكترونية الصادرة عن الهيئة على أن يُبلغ البائع الهيئة مرة أخرى بعد عودة الاتصال.</p>
            <p style="margin:0 0 12px;">أبلغ الهيئة مرة أخرى عبر النموذج المخصص في موقعها الإلكتروني (zatca.gov.sa)، ثم سجّل ذلك في صفحة «الإبلاغ المتأخر» في البوابة.</p>
        @else
            <h1 style="margin:0 0 12px;font-size:20px;line-height:1.4;font-weight:600;">فواتير لم يُبلَّغ عنها خلال 24 ساعة لدى {{ $organizationName }}</h1>
            <p style="margin:0 0 12px;">لم يتمكن النظام من الإبلاغ عن الفواتير المبسطة أدناه لدى هيئة الزكاة والضريبة والجمارك خلال 24 ساعة من إصدارها. ما زال النظام يعيد المحاولة على فترات منتظمة ويحتفظ بسجل لكل محاولة، وسيُبلغ عنها فور عودة الاتصال.</p>
            <p style="margin:0 0 12px;">تنص الإرشادات التفصيلية للفوترة الإلكترونية الصادرة عن الهيئة على أن يُبلغ البائع الهيئة عبر نموذج مخصص في موقعها الإلكتروني إذا تعذّر الإبلاغ خلال 24 ساعة. أبلغ الهيئة الآن عبر موقعها (zatca.gov.sa)، ثم سجّل ذلك في صفحة «الإبلاغ المتأخر» في البوابة. وستصلك رسالة أخرى بعد الإبلاغ عنها لتُبلغ الهيئة مرة أخرى.</p>
        @endif
    </div>

    <div dir="ltr" lang="en" style="text-align:left;margin-top:20px;">
        @if ($resolved)
            <h1 style="margin:0 0 12px;font-size:20px;line-height:1.4;font-weight:600;">Late invoices now reported for {{ $organizationName }}</h1>
            <p style="margin:0 0 12px;">The simplified invoices below have now reached ZATCA, after their twenty-four hours had passed. ZATCA's Detailed Guidelines for E-Invoicing ask the seller to notify ZATCA again once the connection is restored.</p>
            <p style="margin:0 0 12px;">Notify ZATCA again through the dedicated form on its website (zatca.gov.sa), then record it on the Late reporting page of the portal.</p>
        @else
            <h1 style="margin:0 0 12px;font-size:20px;line-height:1.4;font-weight:600;">Invoices not reported within 24 hours for {{ $organizationName }}</h1>
            <p style="margin:0 0 12px;">The simplified invoices below could not be reported to ZATCA within 24 hours of being issued. The platform keeps trying at regular intervals, keeps a record of every attempt, and reports them as soon as ZATCA can be reached.</p>
            <p style="margin:0 0 12px;">ZATCA's Detailed Guidelines for E-Invoicing ask a seller who cannot report within 24 hours to notify ZATCA through a dedicated form on its website. Notify ZATCA now on its website (zatca.gov.sa), then record it on the Late reporting page of the portal. You will be emailed again once they are reported, to notify ZATCA again.</p>
        @endif
    </div>

    <table role="presentation" style="margin:8px 0 20px;border-collapse:collapse;font-size:14px;" dir="ltr">
        @foreach ($documents as $document)
            <tr>
                <td style="padding:4px 16px 4px 0;font-weight:600;">{{ $document['number'] }}</td>
                <td style="padding:4px 0;">{{ $document['issued_at'] }} UTC</td>
            </tr>
        @endforeach
    </table>

    @include('emails.partials.button', ['href' => $url, 'label' => 'الإبلاغ المتأخر / Late reporting'])
@endsection
