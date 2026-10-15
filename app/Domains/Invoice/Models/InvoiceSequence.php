<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * How an organization numbers one kind of document.
 *
 * A pattern of literal text and tokens - {YYYY}, {YY}, {MM}, and {SEQ} for
 * the zero-padded counter - with a reset period. InvoiceNumbering allocates
 * from it; this holds the rules for turning a counter into a number.
 */
class InvoiceSequence extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const ORGANIZATION = 'organization';

    public const RESETS = ['never', 'yearly', 'monthly'];

    /**
     * What each document type starts as, before anyone configures it.
     *
     * @var array<string, string>
     */
    public const DEFAULT_PATTERNS = [
        'invoice' => 'INV-{YYYY}-{SEQ}',
        'credit_note' => 'CN-{YYYY}-{SEQ}',
        'debit_note' => 'DN-{YYYY}-{SEQ}',
        'prepayment' => 'PRE-{YYYY}-{SEQ}',
    ];

    public const DEFAULT_PADDING = 5;

    public const DEFAULT_RESET = 'yearly';

    protected $fillable = [
        'org_id',
        'branch_id',
        'scope',
        'document_type',
        'pattern',
        'padding',
        'reset',
        'next_number',
        'period',
    ];

    protected function casts(): array
    {
        return [
            'padding' => 'integer',
            'next_number' => 'integer',
        ];
    }

    /**
     * The number a counter value becomes on a given day.
     */
    public function format(int $counter, DateTimeInterface $on): string
    {
        return strtr($this->pattern, [
            '{YYYY}' => $on->format('Y'),
            '{YY}' => $on->format('y'),
            '{MM}' => $on->format('m'),
            '{SEQ}' => str_pad((string) $counter, max(1, $this->padding), '0', STR_PAD_LEFT),
        ]);
    }

    /**
     * The period a day falls in, which the counter restarts at the start of.
     */
    public function periodOf(DateTimeInterface $on): ?string
    {
        return match ($this->reset) {
            'yearly' => $on->format('Y'),
            'monthly' => $on->format('Y-m'),
            default => null,
        };
    }
}
