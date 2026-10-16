<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Peppol\Exceptions;

use RuntimeException;

/**
 * The provider gave no verdict on the document: unreachable, throttled,
 * refusing our credentials, or failing itself. Says nothing about the
 * document, so it is sent again later.
 */
class AspUnavailableException extends RuntimeException {}
