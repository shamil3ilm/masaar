<?php

declare(strict_types=1);

namespace App\Notifications\HR;

use App\Models\HR\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeaveRequestApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public LeaveRequest $leaveRequest
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $leaveType = $this->leaveRequest->leaveType;

        return (new MailMessage)
            ->subject("Leave Request Approved: {$leaveType->name}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Your leave request has been **approved**.")
            ->line("**Leave Type:** {$leaveType->name}")
            ->line("**From:** {$this->leaveRequest->start_date->format('M d, Y')}")
            ->line("**To:** {$this->leaveRequest->end_date->format('M d, Y')}")
            ->line("**Total Days:** {$this->leaveRequest->total_days}")
            ->action('View Details', url("/hr/leave-requests/{$this->leaveRequest->id}"))
            ->line('Enjoy your time off!');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'leave_request_approved',
            'leave_request_id' => $this->leaveRequest->id,
            'leave_type' => $this->leaveRequest->leaveType->name,
            'start_date' => $this->leaveRequest->start_date->format('Y-m-d'),
            'end_date' => $this->leaveRequest->end_date->format('Y-m-d'),
            'total_days' => $this->leaveRequest->total_days,
        ];
    }
}
