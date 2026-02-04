<?php

declare(strict_types=1);

namespace App\Listeners\HR;

use App\Events\HR\LeaveRequestApproved;
use App\Notifications\HR\LeaveRequestApprovedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyEmployeeLeaveApproval implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(LeaveRequestApproved $event): void
    {
        $leaveRequest = $event->leaveRequest;
        $employee = $leaveRequest->employee;

        // Get the user associated with the employee
        $user = $employee->user;

        if (!$user) {
            return;
        }

        $user->notify(new LeaveRequestApprovedNotification($leaveRequest));
    }
}
