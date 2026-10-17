<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Exceptions;

use App\Support\Errors\ComplianceException;
use App\Support\Errors\ErrorCode;

/**
 * ZATCA's compliance exception: the taxonomy of ComplianceException, with
 * the factories the Fatoora client, signing and onboarding raise it through.
 */
class FatooraException extends ComplianceException
{
    /**
     * Create a validation exception.
     */
    public static function validation(string $message, ?ErrorCode $code = null, array $context = []): self
    {
        return new self(
            $message,
            $code ?? ErrorCode::VAL_INVALID_FORMAT,
            $context
        );
    }

    /**
     * Create a certificate exception.
     */
    public static function certificate(string $message, ?ErrorCode $code = null, array $context = []): self
    {
        return new self(
            $message,
            $code ?? ErrorCode::CERT_NOT_FOUND,
            $context
        );
    }

    /**
     * Create a network exception.
     */
    public static function network(string $message, ?ErrorCode $code = null, array $context = []): self
    {
        return new self(
            $message,
            $code ?? ErrorCode::NET_CONNECTION_FAILED,
            $context
        );
    }

    /**
     * Create an environment mismatch exception.
     *
     * Used when sandbox API keys attempt to access production resources.
     */
    public static function environmentMismatch(string $message, array $context = []): self
    {
        return new self(
            $message,
            ErrorCode::AUTH_ENVIRONMENT_MISMATCH,
            $context
        );
    }

    /**
     * Create a not onboarded exception.
     *
     * Used when organization hasn't completed ZATCA onboarding.
     */
    public static function notOnboarded(string $message, array $context = []): self
    {
        return new self(
            $message,
            ErrorCode::CERT_NOT_FOUND,
            $context
        );
    }

    /**
     * Create a missing credentials exception.
     *
     * Used when PCSID credentials file doesn't exist.
     */
    public static function missingCredentials(string $message, array $context = []): self
    {
        return new self(
            $message,
            ErrorCode::CERT_NOT_FOUND,
            $context
        );
    }

    /**
     * Create an invalid credentials exception.
     *
     * Used when PCSID credentials are corrupted or incomplete.
     */
    public static function invalidCredentials(string $message, array $context = []): self
    {
        return new self(
            $message,
            // CERT_INVALID is not a case. Naming it made this factory fatal
            // with "Undefined constant" instead of raising the exception, so a
            // taxpayer with corrupted credentials got a crash rather than the
            // message below telling them to re-run onboarding.
            ErrorCode::CERT_INVALID_FORMAT,
            $context
        );
    }
}
