<?php

declare(strict_types=1);

namespace App\Domains\Billing\Exceptions;

use DomainException;

/**
 * The card gateway would not do what was asked, or could not be reached;
 * the message says so in words a person can act on.
 */
final class PaymentGatewayException extends DomainException {}
