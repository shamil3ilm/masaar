<?php

declare(strict_types=1);

namespace App\Domains\Billing\Reconciliation\Models;

use App\Domains\Billing\Reconciliation\StatementKind;
use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bank statement or card settlement report brought in: the platform's
 * own money, so no customer's. Its file is kept privately until pruned.
 */
class StatementImport extends Model
{
    use HasUuids;
    use RecordsActors;

    /** The source of a card settlement report: the gateway, not a bank account. */
    public const GATEWAY = 'card-gateway';

    protected $fillable = [
        'kind',
        'source',
        'profile',
        'account_identifier',
        'currency',
        'statement_reference',
        'period_from',
        'period_to',
        'opening_balance',
        'closing_balance',
        'file_hash',
        'file_path',
        'file_name',
        'file_size',
        'file_removed_at',
        'lines_total',
        'lines_new',
        'lines_duplicate',
    ];

    /** @return array<string, string> */
    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by'];
    }

    protected function casts(): array
    {
        return [
            'kind' => StatementKind::class,
            'period_from' => 'date',
            'period_to' => 'date',
            'opening_balance' => 'decimal:2',
            'closing_balance' => 'decimal:2',
            'file_size' => 'integer',
            'file_removed_at' => 'datetime',
            'lines_total' => 'integer',
            'lines_new' => 'integer',
            'lines_duplicate' => 'integer',
        ];
    }

    /** @return HasMany<StatementLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StatementLine::class, 'import_id');
    }
}
