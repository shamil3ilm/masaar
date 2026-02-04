<?php

use App\Http\Controllers\Api\V1\HR\AttendanceController;
use App\Http\Controllers\Api\V1\HR\EmployeeController;
use App\Http\Controllers\Api\V1\HR\EmployeeSelfServiceController;
use App\Http\Controllers\Api\V1\HR\HRDashboardController;
use App\Http\Controllers\Api\V1\HR\HRReportsController;
use App\Http\Controllers\Api\V1\HR\LeaveController;
use App\Http\Controllers\Api\V1\HR\PayrollController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| HR API Routes
|--------------------------------------------------------------------------
|
| All routes are prefixed with /api/v1/hr
|
*/

Route::middleware(['auth:api'])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Employee Self-Service (ESS)
    |--------------------------------------------------------------------------
    */
    Route::prefix('me')->group(function () {
        Route::get('/profile', [EmployeeSelfServiceController::class, 'profile'])->name('hr.ess.profile');
        Route::get('/attendance', [EmployeeSelfServiceController::class, 'myAttendance'])->name('hr.ess.attendance');
        Route::post('/check-in', [EmployeeSelfServiceController::class, 'checkIn'])->name('hr.ess.check-in');
        Route::post('/check-out', [EmployeeSelfServiceController::class, 'checkOut'])->name('hr.ess.check-out');
        Route::get('/leave-balances', [EmployeeSelfServiceController::class, 'myLeaveBalances'])->name('hr.ess.leave-balances');
        Route::get('/leave-requests', [EmployeeSelfServiceController::class, 'myLeaveRequests'])->name('hr.ess.leave-requests');
        Route::post('/leave-requests', [EmployeeSelfServiceController::class, 'submitLeaveRequest'])->name('hr.ess.leave-request.store');
        Route::post('/leave-requests/{id}/cancel', [EmployeeSelfServiceController::class, 'cancelLeaveRequest'])->name('hr.ess.leave-request.cancel');
        Route::get('/payslips', [EmployeeSelfServiceController::class, 'myPayslips'])->name('hr.ess.payslips');
        Route::get('/payslips/{id}', [EmployeeSelfServiceController::class, 'showPayslip'])->name('hr.ess.payslip.show');
        Route::get('/payslips/{id}/download', [EmployeeSelfServiceController::class, 'downloadPayslip'])->name('hr.ess.payslip.download');
        Route::get('/salary-breakdown', [EmployeeSelfServiceController::class, 'salaryBreakdown'])->name('hr.ess.salary-breakdown');
        Route::get('/loans', [EmployeeSelfServiceController::class, 'myLoans'])->name('hr.ess.loans');
        Route::get('/documents', [EmployeeSelfServiceController::class, 'myDocuments'])->name('hr.ess.documents');
    });

    // Employee Directory & Holidays (accessible to all employees)
    Route::get('/directory', [EmployeeSelfServiceController::class, 'directory'])->name('hr.directory');
    Route::get('/holidays', [EmployeeSelfServiceController::class, 'holidays'])->name('hr.holidays');

    /*
    |--------------------------------------------------------------------------
    | Employees
    |--------------------------------------------------------------------------
    */
    Route::prefix('employees')->group(function () {
        Route::get('/', [EmployeeController::class, 'index'])->name('hr.employees.index');
        Route::post('/', [EmployeeController::class, 'store'])->name('hr.employees.store');
        Route::get('/statistics', [EmployeeController::class, 'statistics'])->name('hr.employees.statistics');
        Route::get('/expiring-documents', [EmployeeController::class, 'expiringDocuments'])->name('hr.employees.expiring-documents');
        Route::get('/{employee}', [EmployeeController::class, 'show'])->name('hr.employees.show');
        Route::put('/{employee}', [EmployeeController::class, 'update'])->name('hr.employees.update');
        Route::post('/{employee}/salary', [EmployeeController::class, 'assignSalary'])->name('hr.employees.assign-salary');
        Route::post('/{employee}/confirm', [EmployeeController::class, 'confirm'])->name('hr.employees.confirm');
        Route::post('/{employee}/terminate', [EmployeeController::class, 'terminate'])->name('hr.employees.terminate');
    });

    /*
    |--------------------------------------------------------------------------
    | Attendance
    |--------------------------------------------------------------------------
    */
    Route::prefix('attendance')->group(function () {
        Route::get('/', [AttendanceController::class, 'index'])->name('hr.attendance.index');
        Route::post('/check-in', [AttendanceController::class, 'checkIn'])->name('hr.attendance.check-in');
        Route::post('/check-out', [AttendanceController::class, 'checkOut'])->name('hr.attendance.check-out');
        Route::post('/mark', [AttendanceController::class, 'mark'])->name('hr.attendance.mark');
        Route::post('/generate', [AttendanceController::class, 'generate'])->name('hr.attendance.generate');
        Route::get('/today-status', [AttendanceController::class, 'todayStatus'])->name('hr.attendance.today-status');
        Route::get('/employee-summary', [AttendanceController::class, 'employeeSummary'])->name('hr.attendance.employee-summary');
    });

    /*
    |--------------------------------------------------------------------------
    | Leave Management
    |--------------------------------------------------------------------------
    */
    Route::prefix('leave')->group(function () {
        Route::get('/types', [LeaveController::class, 'leaveTypes'])->name('hr.leave.types');
        Route::get('/requests', [LeaveController::class, 'index'])->name('hr.leave.requests.index');
        Route::post('/requests', [LeaveController::class, 'store'])->name('hr.leave.requests.store');
        Route::get('/requests/{leaveRequest}', [LeaveController::class, 'show'])->name('hr.leave.requests.show');
        Route::post('/requests/{leaveRequest}/submit', [LeaveController::class, 'submit'])->name('hr.leave.requests.submit');
        Route::post('/requests/{leaveRequest}/approve', [LeaveController::class, 'approve'])->name('hr.leave.requests.approve');
        Route::post('/requests/{leaveRequest}/reject', [LeaveController::class, 'reject'])->name('hr.leave.requests.reject');
        Route::post('/requests/{leaveRequest}/cancel', [LeaveController::class, 'cancel'])->name('hr.leave.requests.cancel');
        Route::get('/balances', [LeaveController::class, 'balances'])->name('hr.leave.balances');
        Route::post('/initialize-balances', [LeaveController::class, 'initializeBalances'])->name('hr.leave.initialize-balances');
        Route::get('/summary', [LeaveController::class, 'summary'])->name('hr.leave.summary');
    });

    /*
    |--------------------------------------------------------------------------
    | Payroll
    |--------------------------------------------------------------------------
    */
    Route::prefix('payroll')->group(function () {
        // Payroll Periods
        Route::get('/periods', [PayrollController::class, 'periods'])->name('hr.payroll.periods.index');
        Route::post('/periods', [PayrollController::class, 'createPeriod'])->name('hr.payroll.periods.store');
        Route::get('/periods/{payrollPeriod}', [PayrollController::class, 'showPeriod'])->name('hr.payroll.periods.show');
        Route::post('/periods/{payrollPeriod}/generate', [PayrollController::class, 'generatePayslips'])->name('hr.payroll.periods.generate');
        Route::get('/periods/{payrollPeriod}/summary', [PayrollController::class, 'periodSummary'])->name('hr.payroll.periods.summary');
        Route::post('/periods/{payrollPeriod}/close', [PayrollController::class, 'closePeriod'])->name('hr.payroll.periods.close');
        Route::post('/periods/{payrollPeriod}/generate-single', [PayrollController::class, 'generateSinglePayslip'])->name('hr.payroll.periods.generate-single');

        // Payslips
        Route::get('/payslips', [PayrollController::class, 'payslips'])->name('hr.payroll.payslips.index');
        Route::get('/payslips/{payslip}', [PayrollController::class, 'showPayslip'])->name('hr.payroll.payslips.show');
        Route::post('/payslips/{payslip}/submit', [PayrollController::class, 'submitPayslip'])->name('hr.payroll.payslips.submit');
        Route::post('/payslips/{payslip}/approve', [PayrollController::class, 'approvePayslip'])->name('hr.payroll.payslips.approve');
        Route::post('/payslips/{payslip}/pay', [PayrollController::class, 'markAsPaid'])->name('hr.payroll.payslips.pay');
        Route::post('/payslips/bulk-approve', [PayrollController::class, 'bulkApprove'])->name('hr.payroll.payslips.bulk-approve');
        Route::post('/payslips/bulk-pay', [PayrollController::class, 'bulkPay'])->name('hr.payroll.payslips.bulk-pay');
    });

    /*
    |--------------------------------------------------------------------------
    | HR Reports & Analytics
    |--------------------------------------------------------------------------
    */
    Route::prefix('reports')->group(function () {
        Route::get('/dashboard', [HRReportsController::class, 'dashboard'])->name('hr.reports.dashboard');
        Route::get('/headcount', [HRReportsController::class, 'headcount'])->name('hr.reports.headcount');
        Route::get('/turnover', [HRReportsController::class, 'turnover'])->name('hr.reports.turnover');
        Route::get('/attendance', [HRReportsController::class, 'attendance'])->name('hr.reports.attendance');
        Route::get('/leave-analysis', [HRReportsController::class, 'leaveAnalysis'])->name('hr.reports.leave-analysis');
        Route::get('/payroll-summary', [HRReportsController::class, 'payrollSummary'])->name('hr.reports.payroll-summary');
    });

    /*
    |--------------------------------------------------------------------------
    | Statutory Deductions
    |--------------------------------------------------------------------------
    */
    Route::prefix('statutory')->group(function () {
        Route::get('/config', [HRReportsController::class, 'statutoryConfig'])->name('hr.statutory.config');
        Route::post('/calculate', [HRReportsController::class, 'calculateStatutory'])->name('hr.statutory.calculate');
        Route::get('/compliance', [HRReportsController::class, 'statutoryCompliance'])->name('hr.statutory.compliance');
    });

    /*
    |--------------------------------------------------------------------------
    | HR Dashboard Widgets
    |--------------------------------------------------------------------------
    */
    Route::prefix('dashboard')->group(function () {
        Route::get('/', [HRDashboardController::class, 'index'])->name('hr.dashboard.index');
        Route::get('/summary', [HRDashboardController::class, 'summary'])->name('hr.dashboard.summary');
        Route::get('/headcount/department', [HRDashboardController::class, 'headcountByDepartment'])->name('hr.dashboard.headcount.department');
        Route::get('/headcount/status', [HRDashboardController::class, 'headcountByStatus'])->name('hr.dashboard.headcount.status');
        Route::get('/attendance/today', [HRDashboardController::class, 'attendanceToday'])->name('hr.dashboard.attendance.today');
        Route::get('/attendance/trend', [HRDashboardController::class, 'attendanceTrend'])->name('hr.dashboard.attendance.trend');
        Route::get('/leave', [HRDashboardController::class, 'leaveSummary'])->name('hr.dashboard.leave');
        Route::get('/pending-approvals', [HRDashboardController::class, 'pendingApprovals'])->name('hr.dashboard.pending-approvals');
        Route::get('/payroll', [HRDashboardController::class, 'payrollSummary'])->name('hr.dashboard.payroll');
        Route::get('/birthdays', [HRDashboardController::class, 'birthdays'])->name('hr.dashboard.birthdays');
        Route::get('/anniversaries', [HRDashboardController::class, 'anniversaries'])->name('hr.dashboard.anniversaries');
        Route::get('/document-alerts', [HRDashboardController::class, 'documentAlerts'])->name('hr.dashboard.document-alerts');
        Route::get('/new-joiners', [HRDashboardController::class, 'newJoiners'])->name('hr.dashboard.new-joiners');
        Route::get('/recent-exits', [HRDashboardController::class, 'recentExits'])->name('hr.dashboard.recent-exits');
        Route::get('/demographics', [HRDashboardController::class, 'demographics'])->name('hr.dashboard.demographics');
    });
});
