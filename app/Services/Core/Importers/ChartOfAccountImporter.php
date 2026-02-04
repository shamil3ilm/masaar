<?php

declare(strict_types=1);

namespace App\Services\Core\Importers;

use App\Models\Accounting\ChartOfAccount;
use App\Models\Core\ImportJob;
use App\Services\Core\ImporterInterface;

class ChartOfAccountImporter implements ImporterInterface
{
    public function importRow(array $data, ImportJob $importJob, array $options = []): mixed
    {
        // Check for existing account
        $existing = ChartOfAccount::where('organization_id', $importJob->organization_id)
            ->where('code', $data['code'])
            ->first();

        if ($existing && !($options['update_existing'] ?? false)) {
            // Skip existing if not updating
            return $existing;
        }

        // Resolve parent account
        $parentId = null;
        if (!empty($data['parent_code'])) {
            $parent = ChartOfAccount::where('organization_id', $importJob->organization_id)
                ->where('code', $data['parent_code'])
                ->first();
            $parentId = $parent?->id;
        }

        // Validate type
        $validTypes = ['asset', 'liability', 'equity', 'income', 'expense'];
        $type = strtolower($data['type'] ?? 'asset');
        if (!in_array($type, $validTypes)) {
            throw new \InvalidArgumentException("Invalid account type: {$data['type']}. Must be one of: " . implode(', ', $validTypes));
        }

        $accountData = [
            'organization_id' => $importJob->organization_id,
            'code' => $data['code'],
            'name' => $data['name'],
            'type' => $type,
            'sub_type' => $data['sub_type'] ?? null,
            'parent_id' => $parentId,
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'is_system' => false,
        ];

        if ($existing) {
            $existing->update($accountData);
            return $existing;
        }

        return ChartOfAccount::create($accountData);
    }
}
