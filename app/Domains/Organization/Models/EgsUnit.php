<?php

declare(strict_types=1);

namespace App\Domains\Organization\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One till: a unit of the organization's e-invoicing solution, with its own
 * certificate, counter, chain, numbering series and printer.
 *
 * Head office is a unit too, under the organization's own id and no branch:
 * what a branch with no unit of its own issues on. A branch's first unit has
 * the branch's id, so the device, credentials and numbering series it had
 * before units were kept apart carry on unchanged; a till added after it
 * gets an id of its own.
 *
 * Never deleted - the documents it signed name it - but retired, after
 * which it issues nothing again. host says where its key and chain live:
 * the cloud, for now.
 *
 * @property string $id
 * @property string $org_id
 * @property string|null $branch_id
 * @property string|null $name
 * @property int|null $till_number
 * @property string|null $egs_serial
 * @property string $host
 * @property string $status
 * @property string $onboarding_status
 * @property Carbon|null $cert_expires_at
 * @property Carbon|null $retired_at
 */
class EgsUnit extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;

    public const HOST_CLOUD = 'cloud';

    /** Issues, and files what it issued. */
    public const ACTIVE = 'active';

    /** Issues nothing more; files what it signed, then is retired. */
    public const RETIRING = 'retiring';

    /** Issues nothing again: nothing it signed is left to file. */
    public const RETIRED = 'retired';

    /** Its machine is gone: issues nothing again. */
    public const LOST = 'lost';

    protected $table = 'egs_units';

    protected $fillable = [
        'org_id',
        'branch_id',
        'name',
        'till_number',
        'egs_serial',
        'host',
        'status',
        'onboarding_status',
        'cert_expires_at',
        'retired_at',
    ];

    protected $casts = [
        'till_number' => 'integer',
        'cert_expires_at' => 'datetime',
        'retired_at' => 'datetime',
    ];

    protected $attributes = [
        'host' => self::HOST_CLOUD,
        'status' => self::ACTIVE,
        'onboarding_status' => Branch::STATUS_PENDING,
    ];

    /**
     * A till takes the organization's next number, counted across its
     * branches and its tills, a removed branch's included, so no two
     * numbering series of one organization carry the same mark.
     */
    protected static function booted(): void
    {
        static::creating(function (EgsUnit $unit): void {
            if ($unit->branch_id !== null) {
                $unit->till_number ??= self::nextTillNumber((string) $unit->org_id);
            }
        });
    }

    /** The organization's next till number, after every branch's and unit's. */
    public static function nextTillNumber(string $organizationId): int
    {
        $branches = (int) Branch::withoutTenantScope(fn () => Branch::withTrashed()->where('org_id', $organizationId)->max('till_number'));
        $units = (int) static::withoutTenantScope(fn () => static::query()->where('org_id', $organizationId)->max('till_number'));

        return 1 + max($branches, $units);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    /** Head office's own unit: the organization's id, under no branch. */
    public function isHeadOffice(): bool
    {
        return $this->branch_id === null && $this->id === $this->org_id;
    }

    /** It may issue a new document: active, and not retiring, retired or lost. */
    public function issues(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /** It may still file what it signed: anything but retired or lost. */
    public function files(): bool
    {
        return in_array($this->status, [self::ACTIVE, self::RETIRING], true);
    }

    public function isCertificateExpired(): bool
    {
        return $this->cert_expires_at !== null && $this->cert_expires_at->isPast();
    }

    /** Days left on its certificate, negative once expired; null with none recorded. */
    public function daysUntilCertificateExpiry(): ?int
    {
        return $this->cert_expires_at === null ? null : (int) now()->diffInDays($this->cert_expires_at, false);
    }

    /** What it is called on screen: its name, else Head office or its number. */
    public function label(): string
    {
        return match (true) {
            filled($this->name) => (string) $this->name,
            $this->isHeadOffice() => __('Head office'),
            default => __('Till :number', ['number' => $this->till_number]),
        };
    }

    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by', 'updated_by' => 'updated_by'];
    }
}
