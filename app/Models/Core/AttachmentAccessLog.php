<?php

declare(strict_types=1);

namespace App\Models\Core;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttachmentAccessLog extends Model
{
    protected $fillable = [
        'attachment_id',
        'user_id',
        'action',
        'ip_address',
        'user_agent',
    ];

    public const ACTION_VIEW = 'view';
    public const ACTION_DOWNLOAD = 'download';
    public const ACTION_SHARE = 'share';

    // Relationships

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
