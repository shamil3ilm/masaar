<?php

declare(strict_types=1);

namespace App\Domains\Logging\Handlers;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Custom Monolog handler that writes logs to organization-specific files.
 *
 * Log file structure:
 * storage/logs/
 *   ├── organizations/
 *   │   ├── {org-uuid}/
 *   │   │   ├── compliance-2026-01-31.log
 *   │   │   ├── submissions-2026-01-31.log
 *   │   │   └── webhooks-2026-01-31.log
 *   │   └── fallback/
 *   │       └── unassigned-2026-01-31.log
 *   └── system/
 *       └── laravel-2026-01-31.log
 */
class OrganizationLogHandler extends RotatingFileHandler
{
    private ?string $organizationId = null;
    private string $logType;
    private string $basePath;

    public function __construct(
        string $logType = 'general',
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
        ?int $filePermission = null,
        bool $useLocking = false,
        int $maxFiles = 30
    ) {
        $this->logType = $logType;
        $this->basePath = storage_path('logs/organizations');

        // Start with fallback path - will be updated when organization is set
        $fallbackPath = $this->basePath . '/fallback/' . $logType . '.log';

        parent::__construct(
            $fallbackPath,
            $maxFiles,
            $level,
            $bubble,
            $filePermission,
            $useLocking
        );
    }

    /**
     * Set the organization ID for this log session.
     */
    public function setOrganizationId(?string $organizationId): void
    {
        $this->organizationId = $organizationId;
        $this->updateFilename();
    }

    /**
     * Get the current organization ID.
     */
    public function getOrganizationId(): ?string
    {
        return $this->organizationId;
    }

    /**
     * Update the filename based on current organization.
     */
    private function updateFilename(): void
    {
        if ($this->organizationId !== null) {
            $directory = $this->basePath . '/' . $this->organizationId;
        } else {
            $directory = $this->basePath . '/fallback';
        }

        // Ensure directory exists
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $this->url = $directory . '/' . $this->logType . '.log';
    }

    /**
     * Write the log record.
     */
    protected function write(LogRecord $record): void
    {
        // Extract organization ID from context if present
        if (isset($record->context['organization_id'])) {
            $this->setOrganizationId($record->context['organization_id']);
        }

        parent::write($record);
    }
}
