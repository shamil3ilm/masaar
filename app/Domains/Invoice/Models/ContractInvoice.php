<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invoice a contract made, with the amounts behind it that the invoice
 * does not state: a stage's (its value, and the retention withheld from
 * it) or a release of retention. Goes with its invoice when a draft is
 * deleted.
 *
 * @property string $id
 * @property string $contract_id
 * @property string $invoice_id
 * @property string|null $stage_id
 * @property string $kind
 * @property string $stage_value
 * @property string $retention
 * @property string $released
 */
class ContractInvoice extends Model
{
    use HasUuids;

    public const STAGE = 'stage';

    public const RELEASE = 'release';

    protected $fillable = ['contract_id', 'invoice_id', 'stage_id', 'kind', 'stage_value', 'retention', 'released'];

    protected function casts(): array
    {
        return [
            'stage_value' => 'decimal:2',
            'retention' => 'decimal:2',
            'released' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<ContractStage, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(ContractStage::class, 'stage_id');
    }
}
