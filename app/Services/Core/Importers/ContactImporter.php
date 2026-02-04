<?php

declare(strict_types=1);

namespace App\Services\Core\Importers;

use App\Models\Core\ImportJob;
use App\Models\Sales\Contact;
use App\Services\Core\ImporterInterface;

class ContactImporter implements ImporterInterface
{
    public function importRow(array $data, ImportJob $importJob, array $options = []): mixed
    {
        $contactType = match ($importJob->entity_type) {
            ImportJob::ENTITY_CUSTOMERS => 'customer',
            ImportJob::ENTITY_SUPPLIERS => 'supplier',
            default => 'customer',
        };

        // Check for existing contact
        $existing = null;
        if ($options['update_existing'] ?? false) {
            $existing = Contact::where('organization_id', $importJob->organization_id)
                ->where('contact_type', $contactType)
                ->where(function ($query) use ($data) {
                    if (!empty($data['email'])) {
                        $query->orWhere('email', $data['email']);
                    }
                    if (!empty($data['tax_number'])) {
                        $query->orWhere('tax_number', $data['tax_number']);
                    }
                    if (!empty($data['company_name'])) {
                        $query->orWhere('company_name', $data['company_name']);
                    }
                })
                ->first();
        }

        $contactData = [
            'organization_id' => $importJob->organization_id,
            'contact_type' => $contactType,
            'company_name' => $data['company_name'] ?? null,
            'contact_name' => $data['contact_name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'website' => $data['website'] ?? null,
            'tax_number' => $data['tax_number'] ?? null,
            'currency_code' => $data['currency_code'] ?? 'SAR',
            'payment_terms' => $data['payment_terms'] ?? 30,
            'credit_limit' => $data['credit_limit'] ?? null,
            'billing_address_line_1' => $data['billing_address_line_1'] ?? null,
            'billing_address_line_2' => $data['billing_address_line_2'] ?? null,
            'billing_city' => $data['billing_city'] ?? null,
            'billing_state' => $data['billing_state'] ?? null,
            'billing_postal_code' => $data['billing_postal_code'] ?? null,
            'billing_country' => $data['billing_country'] ?? null,
            'is_active' => true,
        ];

        if ($existing) {
            $existing->update(array_filter($contactData, fn ($v) => $v !== null));
            return $existing;
        }

        return Contact::create($contactData);
    }
}
