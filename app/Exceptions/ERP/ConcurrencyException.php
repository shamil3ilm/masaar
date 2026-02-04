<?php

declare(strict_types=1);

namespace App\Exceptions\ERP;

class ConcurrencyException extends ErpException
{
    protected string $errorCode = 'CONCURRENCY_CONFLICT';
    protected int $httpStatus = 409; // Conflict

    public static function versionMismatch(
        string $entityType,
        int $entityId,
        int $expectedVersion,
        int $actualVersion
    ): self {
        $message = "{$entityType} has been modified by another user. Please refresh and try again.";

        return new self($message, [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'expected_version' => $expectedVersion,
            'actual_version' => $actualVersion,
        ]);
    }

    public static function recordModified(string $entityType, string $identifier): self
    {
        $message = "{$entityType} '{$identifier}' has been modified. Please refresh and try again.";

        return new self($message, [
            'entity_type' => $entityType,
            'identifier' => $identifier,
        ]);
    }
}
