<?php

declare(strict_types=1);

namespace App\Services\Core;

use App\Models\Core\RecurringProfile;
use App\Models\Core\RecurringProfileLog;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecurringTransactionService
{
    /**
     * Create a new recurring profile.
     */
    public function createProfile(array $data): RecurringProfile
    {
        return DB::transaction(function () use ($data) {
            $profile = RecurringProfile::create([
                'organization_id' => $data['organization_id'],
                'branch_id' => $data['branch_id'] ?? null,
                'name' => $data['name'],
                'profile_type' => $data['profile_type'],
                'source_type' => $data['source_type'],
                'source_id' => $data['source_id'],
                'frequency' => $data['frequency'],
                'interval' => $data['interval'] ?? 1,
                'schedule_config' => $data['schedule_config'] ?? null,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'max_occurrences' => $data['max_occurrences'] ?? null,
                'auto_send' => $data['auto_send'] ?? false,
                'send_reminder' => $data['send_reminder'] ?? false,
                'reminder_days_before' => $data['reminder_days_before'] ?? 3,
                'status' => RecurringProfile::STATUS_ACTIVE,
                'notify_on_creation' => $data['notify_on_creation'] ?? true,
                'notify_email' => $data['notify_email'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $profile->calculateNextRunDate(Carbon::parse($data['start_date'])->subDay());
            $profile->save();

            return $profile;
        });
    }

    /**
     * Update an existing profile.
     */
    public function updateProfile(RecurringProfile $profile, array $data): RecurringProfile
    {
        return DB::transaction(function () use ($profile, $data) {
            $profile->fill([
                'name' => $data['name'] ?? $profile->name,
                'frequency' => $data['frequency'] ?? $profile->frequency,
                'interval' => $data['interval'] ?? $profile->interval,
                'schedule_config' => $data['schedule_config'] ?? $profile->schedule_config,
                'end_date' => $data['end_date'] ?? $profile->end_date,
                'max_occurrences' => $data['max_occurrences'] ?? $profile->max_occurrences,
                'auto_send' => $data['auto_send'] ?? $profile->auto_send,
                'send_reminder' => $data['send_reminder'] ?? $profile->send_reminder,
                'reminder_days_before' => $data['reminder_days_before'] ?? $profile->reminder_days_before,
                'notify_on_creation' => $data['notify_on_creation'] ?? $profile->notify_on_creation,
                'notify_email' => $data['notify_email'] ?? $profile->notify_email,
            ]);

            // Recalculate next run date if schedule changed
            if (isset($data['frequency']) || isset($data['interval']) || isset($data['schedule_config'])) {
                $profile->calculateNextRunDate();
            }

            $profile->save();
            return $profile;
        });
    }

    /**
     * Process all due recurring profiles.
     */
    public function processDueProfiles(?Carbon $date = null): array
    {
        $date = $date ?? today();
        $results = [
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        $dueProfiles = RecurringProfile::dueToRun($date)->get();

        foreach ($dueProfiles as $profile) {
            try {
                $result = $this->processProfile($profile, $date);
                $results['processed']++;

                if ($result['status'] === 'success') {
                    $results['success']++;
                } elseif ($result['status'] === 'skipped') {
                    $results['skipped']++;
                }
            } catch (\Exception $e) {
                $results['processed']++;
                $results['failed']++;
                $results['errors'][] = [
                    'profile_id' => $profile->id,
                    'error' => $e->getMessage(),
                ];

                Log::error('Recurring profile processing failed', [
                    'profile_id' => $profile->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Process a single recurring profile.
     */
    public function processProfile(RecurringProfile $profile, ?Carbon $date = null): array
    {
        $date = $date ?? today();

        if (!$profile->isDueToRun($date)) {
            return [
                'status' => 'skipped',
                'reason' => 'Not due to run',
            ];
        }

        return DB::transaction(function () use ($profile, $date) {
            try {
                // Create the new document based on profile type
                $createdDocument = $this->createDocument($profile);

                // Log the successful creation
                $log = RecurringProfileLog::create([
                    'recurring_profile_id' => $profile->id,
                    'created_type' => get_class($createdDocument),
                    'created_id' => $createdDocument->id,
                    'scheduled_date' => $profile->next_run_date,
                    'created_date' => $date,
                    'status' => RecurringProfileLog::STATUS_SUCCESS,
                ]);

                // Update profile
                $profile->incrementOccurrence();

                // Send notification if configured
                if ($profile->notify_on_creation) {
                    $this->sendNotification($profile, $createdDocument);
                }

                // Auto-send if configured
                if ($profile->auto_send) {
                    $this->autoSendDocument($createdDocument);
                }

                return [
                    'status' => 'success',
                    'document_type' => get_class($createdDocument),
                    'document_id' => $createdDocument->id,
                    'log_id' => $log->id,
                ];
            } catch (\Exception $e) {
                // Log the failure
                RecurringProfileLog::create([
                    'recurring_profile_id' => $profile->id,
                    'created_type' => null,
                    'created_id' => null,
                    'scheduled_date' => $profile->next_run_date,
                    'created_date' => $date,
                    'status' => RecurringProfileLog::STATUS_FAILED,
                    'error_message' => $e->getMessage(),
                ]);

                throw $e;
            }
        });
    }

    /**
     * Create document from recurring profile.
     */
    protected function createDocument(RecurringProfile $profile): Model
    {
        $source = $profile->source;

        if (!$source) {
            throw new \RuntimeException("Source document not found for recurring profile {$profile->id}");
        }

        return match ($profile->profile_type) {
            RecurringProfile::TYPE_INVOICE => $this->createInvoiceFromSource($source, $profile),
            RecurringProfile::TYPE_BILL => $this->createBillFromSource($source, $profile),
            RecurringProfile::TYPE_JOURNAL => $this->createJournalFromSource($source, $profile),
            RecurringProfile::TYPE_EXPENSE => $this->createExpenseFromSource($source, $profile),
            default => throw new \RuntimeException("Unknown profile type: {$profile->profile_type}"),
        };
    }

    /**
     * Create invoice from source.
     */
    protected function createInvoiceFromSource(Model $source, RecurringProfile $profile): Model
    {
        // Get the Invoice model class
        $invoiceClass = 'App\\Models\\Sales\\Invoice';

        // Replicate the source invoice
        $newInvoice = $source->replicate([
            'invoice_number',
            'status',
            'compliance_status',
            'compliance_uuid',
            'compliance_hash',
            'compliance_qr_code',
            'compliance_response',
            'compliance_submitted_at',
            'amount_paid',
            'journal_entry_id',
            'posted_at',
            'sent_at',
        ]);

        // Update dates
        $newInvoice->invoice_date = today();
        $newInvoice->due_date = today()->addDays($source->payment_terms ?? 30);
        $newInvoice->status = 'draft';
        $newInvoice->compliance_status = 'pending';
        $newInvoice->amount_paid = 0;
        $newInvoice->amount_due = $source->total;
        $newInvoice->recurring_profile_id = $profile->id;

        // Generate new invoice number
        $newInvoice->invoice_number = \App\Models\Core\NumberSequence::getNext(
            $profile->organization_id,
            'invoice',
            $profile->branch_id
        );

        $newInvoice->save();

        // Replicate lines
        foreach ($source->lines as $line) {
            $newLine = $line->replicate();
            $newLine->document_id = $newInvoice->id;
            $newLine->save();
        }

        return $newInvoice;
    }

    /**
     * Create bill from source.
     */
    protected function createBillFromSource(Model $source, RecurringProfile $profile): Model
    {
        $billClass = 'App\\Models\\Purchase\\Bill';

        $newBill = $source->replicate([
            'bill_number',
            'status',
            'amount_paid',
            'journal_entry_id',
            'posted_at',
        ]);

        $newBill->bill_date = today();
        $newBill->due_date = today()->addDays($source->payment_terms ?? 30);
        $newBill->status = 'draft';
        $newBill->amount_paid = 0;
        $newBill->amount_due = $source->total;
        $newBill->recurring_profile_id = $profile->id;

        $newBill->bill_number = \App\Models\Core\NumberSequence::getNext(
            $profile->organization_id,
            'bill',
            $profile->branch_id
        );

        $newBill->save();

        foreach ($source->lines as $line) {
            $newLine = $line->replicate();
            $newLine->document_id = $newBill->id;
            $newLine->save();
        }

        return $newBill;
    }

    /**
     * Create journal entry from source.
     */
    protected function createJournalFromSource(Model $source, RecurringProfile $profile): Model
    {
        $journalClass = 'App\\Models\\Accounting\\JournalEntry';

        $newJournal = $source->replicate([
            'entry_number',
            'status',
            'posted_at',
            'posted_by',
        ]);

        $newJournal->entry_date = today();
        $newJournal->status = 'draft';
        $newJournal->recurring_profile_id = $profile->id;

        $newJournal->entry_number = \App\Models\Core\NumberSequence::getNext(
            $profile->organization_id,
            'journal',
            $profile->branch_id
        );

        $newJournal->save();

        foreach ($source->lines as $line) {
            $newLine = $line->replicate();
            $newLine->journal_entry_id = $newJournal->id;
            $newLine->save();
        }

        return $newJournal;
    }

    /**
     * Create expense from source.
     */
    protected function createExpenseFromSource(Model $source, RecurringProfile $profile): Model
    {
        $expenseClass = 'App\\Models\\Accounting\\Expense';

        $newExpense = $source->replicate([
            'expense_number',
            'status',
            'journal_entry_id',
        ]);

        $newExpense->expense_date = today();
        $newExpense->status = 'draft';
        $newExpense->recurring_profile_id = $profile->id;

        $newExpense->expense_number = \App\Models\Core\NumberSequence::getNext(
            $profile->organization_id,
            'expense',
            $profile->branch_id
        );

        $newExpense->save();

        return $newExpense;
    }

    /**
     * Send notification for created document.
     */
    protected function sendNotification(RecurringProfile $profile, Model $document): void
    {
        // TODO: Implement notification sending based on profile settings
        // Could dispatch a job or use the EmailService
    }

    /**
     * Auto-send/post the document.
     */
    protected function autoSendDocument(Model $document): void
    {
        // TODO: Implement auto-send based on document type
        // For invoices, this might mean marking as sent
        // For journal entries, this might mean posting
    }

    /**
     * Get upcoming scheduled transactions.
     */
    public function getUpcoming(int $organizationId, int $days = 30): array
    {
        $endDate = today()->addDays($days);

        return RecurringProfile::where('organization_id', $organizationId)
            ->where('status', RecurringProfile::STATUS_ACTIVE)
            ->where('next_run_date', '<=', $endDate)
            ->orderBy('next_run_date')
            ->get()
            ->map(fn($profile) => [
                'id' => $profile->id,
                'name' => $profile->name,
                'profile_type' => $profile->profile_type,
                'frequency' => $profile->getFrequencyLabel(),
                'next_run_date' => $profile->next_run_date->format('Y-m-d'),
                'remaining_occurrences' => $profile->getRemainingOccurrences(),
            ])
            ->toArray();
    }

    /**
     * Get profile statistics.
     */
    public function getStatistics(int $organizationId): array
    {
        $profiles = RecurringProfile::where('organization_id', $organizationId);

        return [
            'total' => (clone $profiles)->count(),
            'active' => (clone $profiles)->where('status', RecurringProfile::STATUS_ACTIVE)->count(),
            'paused' => (clone $profiles)->where('status', RecurringProfile::STATUS_PAUSED)->count(),
            'completed' => (clone $profiles)->where('status', RecurringProfile::STATUS_COMPLETED)->count(),
            'by_type' => (clone $profiles)->selectRaw('profile_type, count(*) as count')
                ->groupBy('profile_type')
                ->pluck('count', 'profile_type')
                ->toArray(),
            'documents_created_this_month' => RecurringProfileLog::whereHas('profile', function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            })
                ->where('status', RecurringProfileLog::STATUS_SUCCESS)
                ->where('created_at', '>=', now()->startOfMonth())
                ->count(),
        ];
    }

    /**
     * Skip the next occurrence of a profile.
     */
    public function skipNext(RecurringProfile $profile): RecurringProfile
    {
        return DB::transaction(function () use ($profile) {
            RecurringProfileLog::create([
                'recurring_profile_id' => $profile->id,
                'created_type' => null,
                'created_id' => null,
                'scheduled_date' => $profile->next_run_date,
                'created_date' => today(),
                'status' => RecurringProfileLog::STATUS_SKIPPED,
                'error_message' => 'Manually skipped',
            ]);

            $profile->incrementOccurrence();

            return $profile;
        });
    }
}
