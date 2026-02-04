<?php

declare(strict_types=1);

namespace App\Services\Core;

use App\Models\Core\ImportJob;

interface ImporterInterface
{
    /**
     * Import a single row of data.
     */
    public function importRow(array $data, ImportJob $importJob, array $options = []): mixed;
}
