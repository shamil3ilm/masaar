<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Exceptions;

use DomainException;

/**
 * A stored Peppol document was about to be deleted.
 *
 * The XML sent or received is the record the taxpayer must keep, so nothing
 * in the application removes it - as an issued invoice is never deleted.
 */
class DocumentRetainedException extends DomainException
{
    public static function for(string $documentId): self
    {
        return new self("Peppol document {$documentId} is a retained e-invoice record and cannot be deleted.");
    }
}
