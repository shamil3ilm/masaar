<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Concerns\RecordsActors;
use App\Domains\Platform\Website\ContactStatus;
use App\Domains\Platform\Website\ContactTopic;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A message from the home page's contact form. Written by ContactInbox and
 * read by the console's Website inbox only.
 *
 * The platform's own, so not tenant-scoped. Who read and who answered it are
 * set by ContactInbox, never from input; who last changed and removed it by
 * RecordsActors.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string|null $company
 * @property string|null $country
 * @property ContactTopic $topic
 * @property string $message
 * @property string $locale
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property ContactStatus $status
 * @property Carbon|null $read_at
 * @property string|null $read_by
 * @property Carbon|null $replied_at
 * @property string|null $replied_by
 * @property Carbon $created_at
 */
class ContactMessage extends Model
{
    use HasUuids;
    use RecordsActors;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'company',
        'country',
        'topic',
        'message',
        'locale',
        'ip_address',
        'user_agent',
    ];

    protected $attributes = [
        'status' => 'new',
    ];

    protected function casts(): array
    {
        return [
            'topic' => ContactTopic::class,
            'status' => ContactStatus::class,
            'read_at' => 'datetime',
            'replied_at' => 'datetime',
        ];
    }

    public function replier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replied_by')->withTrashed();
    }

    public function reader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'read_by')->withTrashed();
    }
}
