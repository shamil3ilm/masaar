<?php

declare(strict_types=1);

namespace App\Notifications\HR;

use App\Models\HR\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeaveRequestSubmittedNotification extends Notification implements ShouldQueue
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
        $employee = $this->leaveRequest->employee;
        $leaveType = $this->leaveRequest->leaveType;

        return (new MailMessage)
            ->subject("Leave Request: {$employee->getDisplayName()} - {$leaveType->name}")
            ->greeting("Hello {$notifiable->name},")
            ->line("A new leave request has been submitted and requires your approval.")
            ->line("**Employee:** {$employee->getDisplayName()}")
            ->line("**Leave Type:** {$leaveType->name}")
            ->line("**From:** {$this->leaveRequest->start_date->format('M d, Y')}")
            ->line("**To:** {$this->leaveRequest->end_date->format('M d, Y')}")
            ->line("**Total Days:** {$this->leaveRequest->total_days}")
            ->line("**Reason:** " . ($this->leaveRequest->reason ?? 'Not specified'))
            ->action('Review Request', url("/hr/leave-requests/{$this->leaveRequest->id}"))
            ->line('Please review and take appropriate action.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'leave_request_submitted',
            'leave_request_id' => $this->leaveRequest->id,
            'employee_id' => $this->leaveRequest->employee_id,
            'employee_name' => $this->leaveRequest->employee->getDisplayName(),
            'leave_type' => $this->leaveRequest->leaveType->name,
            'start_date' => $this->leaveRequest->start_date->format('Y-m-d'),
            'end_date' => $this->leaveRequest->end_date->format('Y-m-d'),
            'total_days' => $this->leaveRequest->total_days,
        ];
    }
}
