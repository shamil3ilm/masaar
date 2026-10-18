<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One of an organization's own QR codes: a contact card, payment details or
 * a link (BusinessQrCodes), with the fields its kind asks for in data.
 *
 * @property string $id
 * @property string $org_id
 * @property string $kind
 * @property string $label
 * @property array<string, string|null> $data
 */
class BusinessQrCode extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;
    use SoftDeletes;

    public const CONTACT = 'contact';

    public const PAYMENT = 'payment';

    public const LINK = 'link';

    public const KINDS = [self::CONTACT, self::PAYMENT, self::LINK];

    protected $fillable = ['org_id', 'kind', 'label', 'data'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
