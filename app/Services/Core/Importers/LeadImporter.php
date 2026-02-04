<?php

declare(strict_types=1);

namespace App\Services\Core\Importers;

use App\Models\Core\ImportJob;
use App\Models\CRM\Lead;
use App\Services\Core\ImporterInterface;

class LeadImporter implements ImporterInterface
{
    public function importRow(array $data, ImportJob $importJob, array $options = []): mixed
    {
        // Check for existing lead
        $existing = null;
        if ($options['update_existing'] ?? false) {
            $existing = Lead::where('organization_id', $importJob->organization_id)
                ->where(function ($query) use ($data) {
                    if (!empty($data['email'])) {
                        $query->orWhere('email', $data['email']);
                    }
                    if (!empty($data['company_name'])) {
                        $query->orWhere('company_name', $data['company_name']);
                    }
                })
                ->first();
        }

        $leadData = [
            'organization_id' => $importJob->organization_id,
            'company_name' => $data['company_name'],
            'contact_name' => $data['contact_name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'source' => $data['source'] ?? 'import',
            'status' => $data['status'] ?? 'new',
            'industry' => $data['industry'] ?? null,
            'estimated_value' => $data['estimated_value'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $importJob->user_id,
        ];

        if ($existing) {
            $existing->update(array_filter($leadData, fn ($v) => $v !== null));
            return $existing;
        }

        return Lead::create($leadData);
    }
}
