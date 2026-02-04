<?php

declare(strict_types=1);

namespace App\Listeners\HR;

use App\Events\HR\LeaveRequestSubmitted;
use App\Models\Core\User;
use App\Notifications\HR\LeaveRequestSubmittedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

class NotifyLeaveApprover implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(LeaveRequestSubmitted $event): void
    {
        $leaveRequest = $event->leaveRequest;
        $employee = $leaveRequest->employee;

        // Get the employee's reporting manager/supervisor
        $approvers = collect();

        // Add department head
        if ($employee->department?->head_id) {
            $departmentHead = User::find($employee->department->head_id);
            if ($departmentHead) {
                $approvers->push($departmentHead);
            }
        }

        // Add reporting manager if different
        if ($employee->reporting_manager_id && $employee->reporting_manager_id !== $employee->department?->head_id) {
            $manager = User::find($employee->reporting_manager_id);
            if ($manager) {
                $approvers->push($manager);
            }
        }

        // Add HR managers as fallback
        if ($approvers->isEmpty()) {
            $hrManagers = User::whereHas('roles', function ($query) {
                $query->whereIn('slug', ['hr-manager', 'admin']);
            })
                ->where('organization_id', $employee->organization_id)
                ->where('is_active', true)
                ->get();

            $approvers = $hrManagers;
        }

        if ($approvers->isEmpty()) {
            return;
        }

        Notification::send($approvers, new LeaveRequestSubmittedNotification($leaveRequest));
    }
}
