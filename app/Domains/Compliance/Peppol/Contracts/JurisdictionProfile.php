<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Peppol\Contracts;

use App\Domains\Compliance\Peppol\Enums\PintDocumentCategory;
use App\Domains\Compliance\Peppol\Enums\PintTaxCategory;

/**
 * What one country's PINT specialisation fixes about a document.
 *
 * PINT is a shared core with national variants. The structure of the XML is
 * the same everywhere; the identifiers, codes and categories a country
 * accepts are not, and they live behind this so the builder stays generic.
 */
interface JurisdictionProfile
{
    /** ISO 3166-1 alpha-2, as peppol_documents.country records it. */
    public function country(): string;

    /** IBT-024, the specification identifier. */
    public function customizationId(PintDocumentCategory $category): string;

    /** IBT-023, the business process. */
    public function profileId(PintDocumentCategory $category): string;

    /** IBT-003, the invoice or credit note type code. */
    public function typeCode(PintDocumentCategory $category): string;

    /** The scheme of the country's participant identifiers (IBT-034-1). */
    public function endpointScheme(): string;

    /** @return list<PintTaxCategory> */
    public function allowedTaxCategories(PintDocumentCategory $category): array;

    /** The currency tax must also be stated in (IBT-006). */
    public function taxCurrency(): string;

    /** The rate a reverse-charge or margin-scheme line states (it charges none). */
    public function standardRate(): string;
}
