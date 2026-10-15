<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Exceptions;

use RuntimeException;

/**
 * A licence was asked to move to a status its current one does not allow:
 * activating one that is not pending, suspending or reactivating one that is
 * revoked.
 *
 * A refusal written to be read. These were bare RuntimeExceptions, which a
 * database failure is too, so nothing could show one and hide the other.
 */
final class LicenceStateException extends RuntimeException {}
