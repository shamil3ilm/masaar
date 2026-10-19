<?php

use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The product's name
|--------------------------------------------------------------------------
|
| The one place the product's name is written, and the identifiers made
| from it. The name may change while the logo stays: a rename is this file
| (or the environment), never a search through the code - BrandNameTest
| fails on the name anywhere else.
|
| Code reads it through App\Support\Brand. A translated string says :app
| where the name goes, and the translator puts the name in, in the line's
| own language.
|
*/

$name = (string) env('APP_NAME', 'Masaar');
$slug = (string) env('BRAND_SLUG', Str::slug($name));

return [

    // What people call the product, in English and in Arabic.
    'name' => $name,
    'name_ar' => (string) env('BRAND_NAME_AR', 'مسار'),

    // A short lower-case token for identifiers made from the name.
    'slug' => $slug,

    // The operator's registered name, where a page or contract needs it.
    'legal_name' => (string) env('BRAND_LEGAL_NAME', $name),

    // Where a person writes for help, and the address examples show.
    'support_email' => (string) env('BRAND_SUPPORT_EMAIL', 'shamil3ilm@gmail.com'),

    // The host the product is served from.
    'domain' => (string) env('BRAND_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost'),

    // How the platform introduces itself to a server it calls:
    // "<token>-Webhook/1.0".
    'user_agent' => (string) env('BRAND_USER_AGENT', Str::studly($slug)),

    // The start of every metric's name: "<prefix>_invoices_created_today".
    // Dashboards and alert rules name metrics by it, so it is kept apart from
    // the name: a rename need not break them.
    'metrics_prefix' => (string) env('BRAND_METRICS_PREFIX', str_replace('-', '_', $slug)),

    // The solution's name in a device certificate request's serial number
    // ("1-<solution>|2-<model>|3-<serial>"). Only new requests read it: a
    // certificate already issued keeps the serial it was issued against.
    'csr_solution' => (string) env('BRAND_CSR_SOLUTION', $name),

];
