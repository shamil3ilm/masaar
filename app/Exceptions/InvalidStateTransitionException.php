<?php

declare(strict_types=1);

namespace App\Exceptions;

use InvalidArgumentException;

class InvalidStateTransitionException extends InvalidArgumentException
{
    public function __construct(
        public readonly string $currentState,
        public readonly string $attemptedState,
        public readonly array $allowedStates,
        public readonly ?string $modelType = null
    ) {
        $allowed = implode(', ', $allowedStates);
        $model = $modelType ? "{$modelType}: " : '';

        parent::__construct(
            "{$model}Invalid state transition from '{$currentState}' to '{$attemptedState}'. " .
            "Allowed transitions: [{$allowed}]"
        );
    }
}
