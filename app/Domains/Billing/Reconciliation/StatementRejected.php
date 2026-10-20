<?php

declare(strict_types=1);

namespace App\Domains\Billing\Reconciliation;

use DomainException;

/**
 * Why a statement file was not brought in, in words an admin can act on:
 * nothing of it was kept.
 */
final class StatementRejected extends DomainException {}
