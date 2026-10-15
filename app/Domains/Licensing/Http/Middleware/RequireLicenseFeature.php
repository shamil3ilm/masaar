<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Http\Middleware;

use App\Domains\Licensing\Exceptions\LicenseException;
use App\Domains\Licensing\Models\License;
use Closure;
use Illuminate\Http\Request;

/**
 * The licence grants the feature this request uses.
 *
 * Each tier carries a feature list - Starter simplified invoices, Professional
 * adding standard invoices, credit and debit notes and webhooks - and nothing
 * read it, so every licence could do everything. Must run after
 * ValidateLicense, which puts the licence on the request.
 *
 * Usage:
 *   'license.feature:webhook_notifications'  the route needs that feature
 *   'license.feature:documents'              the document being created
 *                                            decides: standard or simplified,
 *                                            and a credit or debit note
 */
class RequireLicenseFeature
{
    /**
     * The argument that derives features from the document in the request.
     */
    public const DOCUMENTS = 'documents';

    public function handle(Request $request, Closure $next, string $feature): mixed
    {
        $license = $request->attributes->get('license');

        if (! $license instanceof License) {
            throw new \RuntimeException('License not found in request. Ensure ValidateLicense middleware runs first.');
        }

        $required = $feature === self::DOCUMENTS ? self::forDocument($request) : [$feature];

        foreach ($required as $name) {
            if (! $license->hasFeature($name)) {
                throw LicenseException::featureNotAvailable($name);
            }
        }

        return $next($request);
    }

    /**
     * The features the document in this request needs.
     *
     * Read before validation, so from the raw input: type, or the pipeline's
     * invoice_type alias for it, and document_type, an invoice when absent.
     * A value that is not one of these is left to validation to refuse.
     *
     * @return list<string>
     */
    private static function forDocument(Request $request): array
    {
        $type = $request->input('type') ?? $request->input('invoice_type');
        $documentType = $request->input('document_type') ?? 'invoice';

        $features = match ($type) {
            'standard' => ['standard_invoices'],
            'simplified' => ['simplified_invoices'],
            default => [],
        };

        return match ($documentType) {
            'credit_note' => [...$features, 'credit_notes'],
            'debit_note' => [...$features, 'debit_notes'],
            default => $features,
        };
    }
}
