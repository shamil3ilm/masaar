<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The product's name and what is made from it, as config/brand.php sets
 * them: the only way code learns what the product is called.
 *
 * Read as asked, never kept, so a test or a deployment that renames the
 * product sees the new name everywhere at once.
 */
final class Brand
{
    /** The name in a language: Arabic's own, English's for every other. */
    public static function name(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return (string) config(str_starts_with($locale, 'ar') ? 'brand.name_ar' : 'brand.name');
    }

    public static function slug(): string
    {
        return (string) config('brand.slug');
    }

    public static function legalName(): string
    {
        return (string) config('brand.legal_name');
    }

    public static function supportEmail(): string
    {
        return (string) config('brand.support_email');
    }

    public static function domain(): string
    {
        return (string) config('brand.domain');
    }

    /** A User-Agent for one of the platform's clients: "<token>-<client>/<version>". */
    public static function userAgent(string $client, string $version = '1.0'): string
    {
        return config('brand.user_agent').'-'.$client.'/'.$version;
    }

    public static function metricsPrefix(): string
    {
        return (string) config('brand.metrics_prefix');
    }

    /** The solution's name as a certificate request's serial number carries it. */
    public static function csrSolution(): string
    {
        return (string) config('brand.csr_solution');
    }
}
