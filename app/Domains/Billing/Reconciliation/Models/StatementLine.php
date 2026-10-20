<?php

declare(strict_types=1);

namespace App\Domains\Billing\Reconciliation\Models;

use App\Domains\Billing\Reconciliation\DTOs\ParsedLine;
use App\Domains\Billing\Reconciliation\LineStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an imported statement, as the bank or the gateway printed
 * it, and where reconciliation has it. Its money and text never change;
 * only its status, and who set it aside and why.
 */
class StatementLine extends Model
{
    use HasUuids;

    protected $fillable = [
        'import_id',
        'source',
        'line_hash',
        'sequence',
        'booked_on',
        'value_on',
        'direction',
        'amount',
        'currency',
        'reference',
        'bank_reference',
        'counterparty_name',
        'counterparty_account',
        'description',
        'gateway_reference',
        'fee_amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'booked_on' => 'date',
            'value_on' => 'date',
            'amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'status' => LineStatus::class,
            'ignored_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<StatementImport, $this> */
    public function statementImport(): BelongsTo
    {
        return $this->belongsTo(StatementImport::class, 'import_id');
    }

    public function isCredit(): bool
    {
        return $this->direction === ParsedLine::CREDIT;
    }

    /**
     * Set aside by an admin, with why - not by itself, as money out is
     * when it is brought in.
     */
    public function setAsideByHand(): bool
    {
        return $this->ignored_reason !== null;
    }

    /** All the text a payer could have put a reference in. */
    public function searchText(): string
    {
        return implode(' ', array_filter([$this->reference, $this->bank_reference, $this->description, $this->counterparty_name]));
    }
}
