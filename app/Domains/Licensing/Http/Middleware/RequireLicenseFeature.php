<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Http\Middleware;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Exceptions\LicenseException;
use App\Domains\Licensing\Models\License;
use App\Domains\Platform\Access\Access;
use App\Domains\Platform\Access\Feature;
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

    public function __construct(private readonly Access $access) {}

    public function handle(Request $request, Closure $next, string $feature): mixed
    {
        $license = $request->attributes->get('license');

        if (! $license instanceof License) {
            throw new \RuntimeException('License not found in request. Ensure ValidateLicense middleware runs first.');
        }

        $required = $feature === self::DOCUMENTS ? self::forDocument($request) : [$feature];

        foreach ($required as $name) {
            if (! $this->grants($license, $name)) {
                throw LicenseException::featureNotAvailable($name);
            }
        }

        return $next($request);
    }

    /**
     * A feature in Access's catalogue is answered by its plan level, which
     * reads the licence's features and lets a platform admin grant one to
     * the organization beyond its plan. Anything else is the licence's own.
     */
    private function grants(License $license, string $name): bool
    {
        $catalogued = Feature::forLicenceFeature($name);

        return $catalogued === null ? $license->hasFeature($name) : $this->access->licenceAllows($license, $catalogued);
    }

    /**
     * The features the document in this request needs.
     *
     * Read before validation, so from the raw input: type, or the pipeline's
     * invoice_type alias for it, and document_type, an invoice when absent.
     * A value that is not one of these is left to validation to refuse.
     *
     * A route that acts on an invoice already stored - generating or filing
     * it - carries no document, so the stored one decides. Without that, an
     * invoice made in the portal could be filed through any licence. The
     * lookup is tenant-scoped: ValidateLicense set the licence's organization.
     *
     * @return list<string>
     */
    private static function forDocument(Request $request): array
    {
        $type = $request->input('type') ?? $request->input('invoice_type');
        $documentType = $request->input('document_type');

        $id = $request->route('invoiceId') ?? $request->route('invoice');

        if ($type === null && is_string($id) && ($invoice = Invoice::find($id)) !== null) {
            $type = self::value($invoice->type);
            $documentType = self::value($invoice->document_type);
        }

        // Issuing itself needs no licence feature, so it drops out; the
        // invoice type's feature comes before the note's, as it always has.
        return array_values(array_filter(array_map(
            fn (Feature $feature): ?string => $feature->licenceFeature(),
            Feature::forDocument(is_string($type) ? $type : null, is_string($documentType) ? $documentType : null),
        )));
    }

    private static function value(mixed $attribute): ?string
    {
        return $attribute instanceof \BackedEnum ? (string) $attribute->value : ($attribute === null ? null : (string) $attribute);
    }
}
