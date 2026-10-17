<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Exceptions;

use RuntimeException;

/**
 * ZATCA did not answer, and sending again cannot file the invoice twice.
 *
 * Thrown by ProcessFatooraSubmission after the failure is recorded, so the
 * queue's backoff and tries apply. Recorded and returned from, as it was, the
 * failure ended the job and nothing sent the invoice again.
 */
final class FilingRetryException extends RuntimeException {}
