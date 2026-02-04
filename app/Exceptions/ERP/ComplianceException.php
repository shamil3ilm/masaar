<?php

declare(strict_types=1);

namespace App\Exceptions\ERP;

class ComplianceException extends ErpException
{
    protected string $errorCode = 'COMPLIANCE_ERROR';
    protected int $httpStatus = 422;

    public static function submissionFailed(
        string $documentType,
        string $documentNumber,
        string $authority,
        ?string $errorMessage = null,
        ?array $errorDetails = null
    ): self {
        $message = "Failed to submit {$documentType} '{$documentNumber}' to {$authority}";
        if ($errorMessage) {
            $message .= ": {$errorMessage}";
        }

        return new self($message, [
            'document_type' => $documentType,
            'document_number' => $documentNumber,
            'authority' => $authority,
            'error_message' => $errorMessage,
            'error_details' => $errorDetails,
        ]);
    }

    public static function rejected(
        string $documentType,
        string $documentNumber,
        string $authority,
        string $rejectionReason,
        ?array $errors = null
    ): self {
        $message = "{$documentType} '{$documentNumber}' was rejected by {$authority}: {$rejectionReason}";

        return new self($message, [
            'document_type' => $documentType,
            'document_number' => $documentNumber,
            'authority' => $authority,
            'rejection_reason' => $rejectionReason,
            'errors' => $errors,
        ]);
    }

    public static function invalidTaxNumber(string $taxNumber, string $country): self
    {
        $message = "Invalid tax number '{$taxNumber}' for {$country}";

        return new self($message, [
            'tax_number' => $taxNumber,
            'country' => $country,
        ]);
    }

    public static function missingComplianceData(string $field, string $documentType): self
    {
        $message = "Missing required compliance field '{$field}' for {$documentType}";

        return new self($message, [
            'field' => $field,
            'document_type' => $documentType,
        ]);
    }
}
