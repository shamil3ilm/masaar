<?php

declare(strict_types=1);

namespace App\Listeners\Inventory;

use App\Events\Inventory\LowStockAlert;
use App\Models\Core\User;
use App\Notifications\Inventory\LowStockNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

class SendLowStockNotification implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(LowStockAlert $event): void
    {
        // Get users who should receive low stock notifications
        // This could be inventory managers, purchasing staff, etc.
        $users = User::whereHas('roles', function ($query) {
            $query->whereIn('slug', ['inventory-manager', 'purchasing-manager', 'admin']);
        })
            ->where('organization_id', $event->product->organization_id)
            ->where('is_active', true)
            ->get();

        if ($users->isEmpty()) {
            return;
        }

        Notification::send($users, new LowStockNotification($event));
    }
}
