<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\CRM\LeadConverted;
use App\Events\CRM\OpportunityWon;
use App\Events\HR\LeaveRequestApproved;
use App\Events\HR\LeaveRequestSubmitted;
use App\Events\HR\PayslipGenerated;
use App\Events\Inventory\LowStockAlert;
use App\Events\Inventory\StockLevelChanged;
use App\Events\Manufacturing\ProductionRecorded;
use App\Events\Manufacturing\WorkOrderCompleted;
use App\Events\Manufacturing\WorkOrderStarted;
use App\Events\Purchase\BillApproved;
use App\Events\Purchase\PurchaseOrderReceived;
use App\Events\Sales\InvoicePaid;
use App\Events\Sales\InvoicePosted;
use App\Events\Sales\PaymentReceived;
use App\Listeners\CRM\CreateOpportunityFromWonListener;
use App\Listeners\HR\NotifyEmployeeLeaveApproval;
use App\Listeners\HR\NotifyLeaveApprover;
use App\Listeners\Inventory\CheckLowStockListener;
use App\Listeners\Inventory\SendLowStockNotification;
use App\Listeners\Manufacturing\UpdateProductCostListener;
use App\Listeners\Sales\UpdateCustomerBalanceListener;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],

        // Inventory Events
        StockLevelChanged::class => [
            CheckLowStockListener::class,
        ],

        LowStockAlert::class => [
            SendLowStockNotification::class,
        ],

        // Sales Events
        InvoicePosted::class => [
            [UpdateCustomerBalanceListener::class, 'handleInvoicePosted'],
        ],

        InvoicePaid::class => [
            // Add listeners for invoice paid event
        ],

        PaymentReceived::class => [
            [UpdateCustomerBalanceListener::class, 'handlePaymentReceived'],
        ],

        // Purchase Events
        BillApproved::class => [
            // Add listeners for bill approved event
        ],

        PurchaseOrderReceived::class => [
            // Add listeners for PO received event
        ],

        // HR Events
        LeaveRequestSubmitted::class => [
            NotifyLeaveApprover::class,
        ],

        LeaveRequestApproved::class => [
            NotifyEmployeeLeaveApproval::class,
        ],

        PayslipGenerated::class => [
            // Add listeners for payslip generated event
        ],

        // Manufacturing Events
        WorkOrderStarted::class => [
            // Add listeners for work order started event
        ],

        WorkOrderCompleted::class => [
            UpdateProductCostListener::class,
        ],

        ProductionRecorded::class => [
            // Add listeners for production recorded event
        ],

        // CRM Events
        LeadConverted::class => [
            // Add listeners for lead converted event
        ],

        OpportunityWon::class => [
            CreateOpportunityFromWonListener::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
