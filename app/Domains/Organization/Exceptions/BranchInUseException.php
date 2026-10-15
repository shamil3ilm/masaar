<?php

declare(strict_types=1);

namespace App\Domains\Organization\Exceptions;

use RuntimeException;

/**
 * A branch was asked to go while invoices still name it.
 *
 * Its invoices are tax documents and keep their branch; the branch can be
 * suspended instead.
 */
final class BranchInUseException extends RuntimeException
{
    public static function hasInvoices(): self
    {
        return new self('Cannot delete branch with existing invoices. Suspend instead.');
    }
}
