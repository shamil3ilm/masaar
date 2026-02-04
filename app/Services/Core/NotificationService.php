<?php

declare(strict_types=1);

namespace App\Services\Core;

use App\Models\Core\Notification;
use App\Models\Core\NotificationPreference;
use App\Models\Core\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class NotificationService
{
    /**
     * Send notification to a user.
     */
    public function send(
        User $user,
        string $type,
        string $title,
        string $message,
        ?Model $notifiable = null,
        ?string $actionUrl = null,
        ?string $actionText = null,
        array $data = [],
        array $channels = ['database']
    ): ?Notification {
        // Check user preferences
        $preferences = $this->getUserPreference($user, $type);
        $enabledChannels = $this->filterEnabledChannels($channels, $preferences);

        if (empty($enabledChannels)) {
            return null;
        }

        $notification = null;

        // Create database notification
        if (in_array('database', $enabledChannels)) {
            $notification = Notification::create([
                'id' => Str::uuid()->toString(),
                'organization_id' => $user->organization_id,
                'user_id' => $user->id,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'icon' => $data['icon'] ?? null,
                'color' => $data['color'] ?? null,
                'action_url' => $actionUrl,
                'action_text' => $actionText,
                'notifiable_type' => $notifiable ? get_class($notifiable) : null,
                'notifiable_id' => $notifiable?->id,
                'data' => $data,
                'channel' => 'database',
                'sent_at' => now(),
            ]);
        }

        // Send email notification
        if (in_array('email', $enabledChannels)) {
            $this->sendEmail($user, $type, $title, $message, $actionUrl, $actionText, $data);
        }

        // Push notification (placeholder for future implementation)
        if (in_array('push', $enabledChannels)) {
            $this->sendPush($user, $title, $message, $data);
        }

        return $notification;
    }

    /**
     * Send notification to multiple users.
     */
    public function sendToMany(
        Collection|array $users,
        string $type,
        string $title,
        string $message,
        ?Model $notifiable = null,
        ?string $actionUrl = null,
        ?string $actionText = null,
        array $data = [],
        array $channels = ['database']
    ): int {
        $count = 0;

        foreach ($users as $user) {
            if ($this->send($user, $type, $title, $message, $notifiable, $actionUrl, $actionText, $data, $channels)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Send notification to all users in organization with specific permission.
     */
    public function sendToUsersWithPermission(
        int $organizationId,
        string $permission,
        string $type,
        string $title,
        string $message,
        ?Model $notifiable = null,
        ?string $actionUrl = null,
        ?string $actionText = null,
        array $data = [],
        array $channels = ['database']
    ): int {
        $users = User::where('organization_id', $organizationId)
            ->where('is_active', true)
            ->get()
            ->filter(fn ($user) => $user->hasPermission($permission));

        return $this->sendToMany($users, $type, $title, $message, $notifiable, $actionUrl, $actionText, $data, $channels);
    }

    /**
     * Send notification to all users in organization.
     */
    public function sendToOrganization(
        int $organizationId,
        string $type,
        string $title,
        string $message,
        ?Model $notifiable = null,
        ?string $actionUrl = null,
        ?string $actionText = null,
        array $data = [],
        array $channels = ['database']
    ): int {
        $users = User::where('organization_id', $organizationId)
            ->where('is_active', true)
            ->get();

        return $this->sendToMany($users, $type, $title, $message, $notifiable, $actionUrl, $actionText, $data, $channels);
    }

    /**
     * Get notifications for a user.
     */
    public function getForUser(
        int $userId,
        ?bool $unreadOnly = null,
        ?string $type = null,
        int $limit = 50
    ): Collection {
        $query = Notification::where('user_id', $userId)
            ->orderByDesc('created_at');

        if ($unreadOnly === true) {
            $query->whereNull('read_at');
        } elseif ($unreadOnly === false) {
            $query->whereNotNull('read_at');
        }

        if ($type) {
            $query->where('type', $type);
        }

        return $query->limit($limit)->get();
    }

    /**
     * Get unread count for user.
     */
    public function getUnreadCount(int $userId): int
    {
        return Notification::where('user_id', $userId)
            ->whereNull('read_at')
            ->count();
    }

    /**
     * Mark notification as read.
     */
    public function markAsRead(string $notificationId, int $userId): bool
    {
        return Notification::where('id', $notificationId)
            ->where('user_id', $userId)
            ->update(['read_at' => now()]) > 0;
    }

    /**
     * Mark all notifications as read for user.
     */
    public function markAllAsRead(int $userId, ?string $type = null): int
    {
        $query = Notification::where('user_id', $userId)
            ->whereNull('read_at');

        if ($type) {
            $query->where('type', $type);
        }

        return $query->update(['read_at' => now()]);
    }

    /**
     * Delete notification.
     */
    public function delete(string $notificationId, int $userId): bool
    {
        return Notification::where('id', $notificationId)
            ->where('user_id', $userId)
            ->delete() > 0;
    }

    /**
     * Delete all read notifications older than X days.
     */
    public function cleanupOld(int $organizationId, int $days = 30): int
    {
        return Notification::where('organization_id', $organizationId)
            ->whereNotNull('read_at')
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }

    /**
     * Get user notification preferences.
     */
    public function getUserPreferences(int $userId): Collection
    {
        return NotificationPreference::where('user_id', $userId)
            ->get()
            ->keyBy('notification_type');
    }

    /**
     * Get user preference for a specific type.
     */
    public function getUserPreference(User $user, string $type): ?NotificationPreference
    {
        return NotificationPreference::where('user_id', $user->id)
            ->where('notification_type', $type)
            ->first();
    }

    /**
     * Update user notification preference.
     */
    public function updateUserPreference(
        int $userId,
        string $type,
        bool $emailEnabled = true,
        bool $databaseEnabled = true,
        bool $pushEnabled = true,
        bool $smsEnabled = false
    ): NotificationPreference {
        return NotificationPreference::updateOrCreate(
            ['user_id' => $userId, 'notification_type' => $type],
            [
                'email_enabled' => $emailEnabled,
                'database_enabled' => $databaseEnabled,
                'push_enabled' => $pushEnabled,
                'sms_enabled' => $smsEnabled,
            ]
        );
    }

    /**
     * Initialize default preferences for a user.
     */
    public function initializeUserPreferences(User $user): void
    {
        $types = Notification::getTypes();

        foreach (array_keys($types) as $type) {
            NotificationPreference::firstOrCreate(
                ['user_id' => $user->id, 'notification_type' => $type],
                [
                    'email_enabled' => $this->shouldEmailByDefault($type),
                    'database_enabled' => true,
                    'push_enabled' => true,
                    'sms_enabled' => false,
                ]
            );
        }
    }

    /**
     * Filter channels based on user preferences.
     */
    protected function filterEnabledChannels(array $requestedChannels, ?NotificationPreference $preference): array
    {
        if (!$preference) {
            // Default: allow database and email
            return array_intersect($requestedChannels, ['database', 'email']);
        }

        return array_filter($requestedChannels, fn ($ch) => $preference->isChannelEnabled($ch));
    }

    /**
     * Determine if notification type should send email by default.
     */
    protected function shouldEmailByDefault(string $type): bool
    {
        // Types that should send email by default
        $emailTypes = [
            Notification::TYPE_INVOICE_OVERDUE,
            Notification::TYPE_PAYMENT_RECEIVED,
            Notification::TYPE_LEAVE_APPROVED,
            Notification::TYPE_LEAVE_REJECTED,
            Notification::TYPE_PAYSLIP_AVAILABLE,
            Notification::TYPE_DOCUMENT_EXPIRING,
            Notification::TYPE_LEAD_ASSIGNED,
            Notification::TYPE_OPPORTUNITY_WON,
            Notification::TYPE_REPORT_READY,
            Notification::TYPE_SYSTEM_ALERT,
        ];

        return in_array($type, $emailTypes);
    }

    /**
     * Send email notification.
     */
    protected function sendEmail(
        User $user,
        string $type,
        string $title,
        string $message,
        ?string $actionUrl,
        ?string $actionText,
        array $data
    ): void {
        if (!$user->email) {
            return;
        }

        try {
            Mail::send(
                'emails.notifications.generic',
                [
                    'user' => $user,
                    'title' => $title,
                    'message' => $message,
                    'actionUrl' => $actionUrl,
                    'actionText' => $actionText,
                    'data' => $data,
                ],
                function ($mail) use ($user, $title) {
                    $mail->to($user->email, $user->name)
                        ->subject($title);
                }
            );
        } catch (\Throwable $e) {
            // Log but don't fail
            \Log::warning("Failed to send notification email: {$e->getMessage()}");
        }
    }

    /**
     * Send push notification (placeholder).
     */
    protected function sendPush(User $user, string $title, string $message, array $data): void
    {
        // TODO: Implement push notifications (Firebase, OneSignal, etc.)
    }

    // ===================== Convenience methods for common notifications =====================

    /**
     * Notify about invoice creation.
     */
    public function notifyInvoiceCreated(Model $invoice, User $createdBy): void
    {
        // Notify sales managers
        $this->sendToUsersWithPermission(
            $invoice->organization_id,
            'sales.invoices.view',
            Notification::TYPE_INVOICE_CREATED,
            'New Invoice Created',
            "Invoice #{$invoice->invoice_number} for {$invoice->customer_name} has been created.",
            $invoice,
            "/sales/invoices/{$invoice->id}",
            'View Invoice',
            ['amount' => $invoice->total, 'created_by' => $createdBy->name]
        );
    }

    /**
     * Notify about payment received.
     */
    public function notifyPaymentReceived(Model $payment): void
    {
        $this->sendToUsersWithPermission(
            $payment->organization_id,
            'sales.payments.view',
            Notification::TYPE_PAYMENT_RECEIVED,
            'Payment Received',
            "Payment of {$payment->currency_code} {$payment->amount} received from {$payment->customer->company_name}.",
            $payment,
            "/sales/payments/{$payment->id}",
            'View Payment',
            ['amount' => $payment->amount],
            ['database', 'email']
        );
    }

    /**
     * Notify about low stock.
     */
    public function notifyLowStock(Model $stockLevel): void
    {
        $this->sendToUsersWithPermission(
            $stockLevel->organization_id,
            'inventory.stock.view',
            Notification::TYPE_STOCK_LOW,
            'Low Stock Alert',
            "Product '{$stockLevel->product->name}' is running low. Current: {$stockLevel->quantity}, Reorder Level: {$stockLevel->reorder_level}",
            $stockLevel,
            "/inventory/products/{$stockLevel->product_id}",
            'View Product',
            ['current_qty' => $stockLevel->quantity, 'reorder_level' => $stockLevel->reorder_level],
            ['database', 'email']
        );
    }

    /**
     * Notify about leave request.
     */
    public function notifyLeaveRequested(Model $leaveRequest): void
    {
        // Notify the employee's manager
        $employee = $leaveRequest->employee;
        $manager = $employee->reportingManager;

        if ($manager?->user) {
            $this->send(
                $manager->user,
                Notification::TYPE_LEAVE_REQUESTED,
                'Leave Request Submitted',
                "{$employee->first_name} {$employee->last_name} has requested {$leaveRequest->total_days} day(s) leave from {$leaveRequest->start_date->format('d M')} to {$leaveRequest->end_date->format('d M')}.",
                $leaveRequest,
                "/hr/leave/requests/{$leaveRequest->id}",
                'Review Request',
                ['employee_name' => $employee->getDisplayName(), 'days' => $leaveRequest->total_days],
                ['database', 'email']
            );
        }
    }

    /**
     * Notify about leave approval.
     */
    public function notifyLeaveApproved(Model $leaveRequest): void
    {
        $employee = $leaveRequest->employee;

        if ($employee->user) {
            $this->send(
                $employee->user,
                Notification::TYPE_LEAVE_APPROVED,
                'Leave Request Approved',
                "Your leave request from {$leaveRequest->start_date->format('d M')} to {$leaveRequest->end_date->format('d M')} has been approved.",
                $leaveRequest,
                "/hr/me/leave-requests",
                'View Details',
                [],
                ['database', 'email']
            );
        }
    }

    /**
     * Notify about payslip availability.
     */
    public function notifyPayslipAvailable(Model $payslip): void
    {
        $employee = $payslip->employee;

        if ($employee->user) {
            $this->send(
                $employee->user,
                Notification::TYPE_PAYSLIP_AVAILABLE,
                'Payslip Available',
                "Your payslip for {$payslip->payrollPeriod->name} is now available.",
                $payslip,
                "/hr/me/payslips/{$payslip->id}",
                'View Payslip',
                ['period' => $payslip->payrollPeriod->name],
                ['database', 'email']
            );
        }
    }
}
