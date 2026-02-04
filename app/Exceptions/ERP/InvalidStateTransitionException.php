<?php

declare(strict_types=1);

namespace App\Exceptions\ERP;

class InvalidStateTransitionException extends ErpException
{
    protected string $errorCode = 'INVALID_STATE_TRANSITION';
    protected int $httpStatus = 422;

    public static function make(
        string $entityType,
        string $currentState,
        string $targetState,
        ?array $allowedTransitions = null
    ): self {
        $message = "Cannot transition {$entityType} from '{$currentState}' to '{$targetState}'";

        return new self($message, [
            'entity_type' => $entityType,
            'current_state' => $currentState,
            'target_state' => $targetState,
            'allowed_transitions' => $allowedTransitions,
        ]);
    }

    public static function cannotEdit(string $entityType, string $currentState): self
    {
        $message = "{$entityType} cannot be edited in '{$currentState}' status";

        return new self($message, [
            'entity_type' => $entityType,
            'current_state' => $currentState,
        ]);
    }

    public static function cannotDelete(string $entityType, string $currentState, ?string $reason = null): self
    {
        $message = "{$entityType} cannot be deleted in '{$currentState}' status";
        if ($reason) {
            $message .= ". {$reason}";
        }

        return new self($message, [
            'entity_type' => $entityType,
            'current_state' => $currentState,
            'reason' => $reason,
        ]);
    }
}
