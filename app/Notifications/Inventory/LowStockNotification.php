<?php

declare(strict_types=1);

namespace App\Notifications\Inventory;

use App\Events\Inventory\LowStockAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LowStockNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public LowStockAlert $alert
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Low Stock Alert: {$this->alert->product->name}")
            ->greeting("Hello {$notifiable->name},")
            ->line("The stock level for **{$this->alert->product->name}** has fallen below the reorder level.")
            ->line("**Product:** {$this->alert->product->sku} - {$this->alert->product->name}")
            ->line("**Warehouse:** {$this->alert->warehouse->name}")
            ->line("**Current Quantity:** {$this->alert->currentQuantity}")
            ->line("**Reorder Level:** {$this->alert->reorderLevel}")
            ->line("**Suggested Order Quantity:** {$this->alert->getSuggestedOrderQuantity()}")
            ->action('View Product', url("/inventory/products/{$this->alert->product->id}"))
            ->line('Please take action to replenish stock.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'low_stock_alert',
            'product_id' => $this->alert->product->id,
            'product_sku' => $this->alert->product->sku,
            'product_name' => $this->alert->product->name,
            'warehouse_id' => $this->alert->warehouse->id,
            'warehouse_name' => $this->alert->warehouse->name,
            'current_quantity' => $this->alert->currentQuantity,
            'reorder_level' => $this->alert->reorderLevel,
            'suggested_order_quantity' => $this->alert->getSuggestedOrderQuantity(),
        ];
    }
}
