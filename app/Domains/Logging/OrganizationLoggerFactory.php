<?php

declare(strict_types=1);

namespace App\Domains\Logging;

use Illuminate\Log\LogManager;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LoggerInterface;

/**
 * Factory for creating organization-specific loggers.
 *
 * Creates separate log files per organization for:
 * - Audit compliance (each org's logs isolated)
 * - Easier debugging per customer
 * - Data isolation requirements
 *
 * Log structure:
 * storage/logs/organizations/{org-id}/
 *   ├── submissions-2026-01-31.log
 *   ├── compliance-2026-01-31.log
 *   └── errors-2026-01-31.log
 */
class OrganizationLoggerFactory
{
    private const MAX_FILES = 90;

    /**
     * Get a logger for a specific organization.
     */
    public static function make(string $organizationId, string $channel = 'general'): LoggerInterface
    {
        $directory = storage_path("logs/organizations/{$organizationId}");

        // Ensure directory exists
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $filename = "{$directory}/{$channel}.log";

        $logger = new Logger("org.{$organizationId}.{$channel}");

        $handler = new RotatingFileHandler(
            $filename,
            self::MAX_FILES,
            Logger::DEBUG,
            true,
            0644
        );

        $logger->pushHandler($handler);
        $logger->pushProcessor(new PsrLogMessageProcessor());
        $logger->pushProcessor(function ($record) use ($organizationId) {
            $record['extra']['organization_id'] = $organizationId;
            $record['extra']['logged_at'] = now()->toIso8601String();
            return $record;
        });

        return $logger;
    }

    /**
     * Get a submission logger for an organization.
     */
    public static function submissions(string $organizationId): LoggerInterface
    {
        return self::make($organizationId, 'submissions');
    }

    /**
     * Get a compliance logger for an organization.
     */
    public static function compliance(string $organizationId): LoggerInterface
    {
        return self::make($organizationId, 'compliance');
    }

    /**
     * Get an error logger for an organization.
     */
    public static function errors(string $organizationId): LoggerInterface
    {
        return self::make($organizationId, 'errors');
    }

    /**
     * Get an audit logger for an organization.
     */
    public static function audit(string $organizationId): LoggerInterface
    {
        return self::make($organizationId, 'audit');
    }

    /**
     * Get a webhook logger for an organization.
     */
    public static function webhooks(string $organizationId): LoggerInterface
    {
        return self::make($organizationId, 'webhooks');
    }
}
