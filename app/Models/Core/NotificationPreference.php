<?php

declare(strict_types=1);

namespace App\Models\Core;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    protected $fillable = [
        'user_id',
        'notification_type',
        'email_enabled',
        'database_enabled',
        'push_enabled',
        'sms_enabled',
    ];

    protected $casts = [
        'email_enabled' => 'boolean',
        'database_enabled' => 'boolean',
        'push_enabled' => 'boolean',
        'sms_enabled' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get enabled channels for this preference.
     */
    public function getEnabledChannels(): array
    {
        $channels = [];

        if ($this->database_enabled) {
            $channels[] = 'database';
        }
        if ($this->email_enabled) {
            $channels[] = 'email';
        }
        if ($this->push_enabled) {
            $channels[] = 'push';
        }
        if ($this->sms_enabled) {
            $channels[] = 'sms';
        }

        return $channels;
    }

    /**
     * Check if a channel is enabled.
     */
    public function isChannelEnabled(string $channel): bool
    {
        return match ($channel) {
            'database' => $this->database_enabled,
            'email' => $this->email_enabled,
            'push' => $this->push_enabled,
            'sms' => $this->sms_enabled,
            default => false,
        };
    }
}
