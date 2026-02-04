<?php

declare(strict_types=1);

namespace App\Exceptions\ERP;

class DocumentLockedException extends ErpException
{
    protected string $errorCode = 'DOCUMENT_LOCKED';
    protected int $httpStatus = 423; // Locked

    public static function make(string $documentType, string $documentNumber, string $reason): self
    {
        $message = "{$documentType} '{$documentNumber}' is locked. {$reason}";

        return new self($message, [
            'document_type' => $documentType,
            'document_number' => $documentNumber,
            'reason' => $reason,
        ]);
    }

    public static function fiscalYearClosed(string $documentType, string $fiscalYear): self
    {
        $message = "Cannot modify {$documentType}. Fiscal year '{$fiscalYear}' is closed.";

        return new self($message, [
            'document_type' => $documentType,
            'fiscal_year' => $fiscalYear,
            'reason' => 'Fiscal year is closed',
        ]);
    }

    public static function periodClosed(string $documentType, string $period): self
    {
        $message = "Cannot modify {$documentType}. Period '{$period}' is closed.";

        return new self($message, [
            'document_type' => $documentType,
            'period' => $period,
            'reason' => 'Period is closed',
        ]);
    }

    public static function hasPayments(string $documentType, string $documentNumber): self
    {
        $message = "{$documentType} '{$documentNumber}' has associated payments and cannot be modified.";

        return new self($message, [
            'document_type' => $documentType,
            'document_number' => $documentNumber,
            'reason' => 'Has associated payments',
        ]);
    }
}
