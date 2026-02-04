<?php

use App\Http\Controllers\Api\V1\Sales\ContactController;
use App\Http\Controllers\Api\V1\Sales\InvoiceController;
use App\Http\Controllers\Api\V1\Sales\PaymentReceivedController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sales API Routes
|--------------------------------------------------------------------------
|
| All routes are prefixed with /api/v1/sales
|
*/

Route::middleware(['auth:api'])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Contacts (Customers/Suppliers)
    |--------------------------------------------------------------------------
    */
    Route::prefix('contacts')->group(function () {
        Route::get('/', [ContactController::class, 'index'])->name('sales.contacts.index');
        Route::post('/', [ContactController::class, 'store'])->name('sales.contacts.store');
        Route::get('/{contact}', [ContactController::class, 'show'])->name('sales.contacts.show');
        Route::put('/{contact}', [ContactController::class, 'update'])->name('sales.contacts.update');
        Route::delete('/{contact}', [ContactController::class, 'destroy'])->name('sales.contacts.destroy');
        Route::get('/{contact}/statement', [ContactController::class, 'statement'])->name('sales.contacts.statement');
        Route::get('/{contact}/balance', [ContactController::class, 'balance'])->name('sales.contacts.balance');
    });

    /*
    |--------------------------------------------------------------------------
    | Invoices
    |--------------------------------------------------------------------------
    */
    Route::prefix('invoices')->group(function () {
        Route::get('/', [InvoiceController::class, 'index'])->name('sales.invoices.index');
        Route::post('/', [InvoiceController::class, 'store'])->name('sales.invoices.store');
        Route::get('/summary', [InvoiceController::class, 'summary'])->name('sales.invoices.summary');
        Route::get('/{invoice}', [InvoiceController::class, 'show'])->name('sales.invoices.show');
        Route::put('/{invoice}', [InvoiceController::class, 'update'])->name('sales.invoices.update');
        Route::delete('/{invoice}', [InvoiceController::class, 'destroy'])->name('sales.invoices.destroy');
        Route::post('/{invoice}/send', [InvoiceController::class, 'send'])->name('sales.invoices.send');
        Route::post('/{invoice}/void', [InvoiceController::class, 'void'])->name('sales.invoices.void');
        Route::post('/{invoice}/credit-note', [InvoiceController::class, 'createCreditNote'])->name('sales.invoices.credit-note');
        Route::get('/{invoice}/compliance-status', [InvoiceController::class, 'complianceStatus'])->name('sales.invoices.compliance-status');
    });

    /*
    |--------------------------------------------------------------------------
    | Payments Received
    |--------------------------------------------------------------------------
    */
    Route::prefix('payments-received')->group(function () {
        Route::get('/', [PaymentReceivedController::class, 'index'])->name('sales.payments.index');
        Route::post('/', [PaymentReceivedController::class, 'store'])->name('sales.payments.store');
        Route::get('/summary', [PaymentReceivedController::class, 'summary'])->name('sales.payments.summary');
        Route::get('/{paymentReceived}', [PaymentReceivedController::class, 'show'])->name('sales.payments.show');
        Route::delete('/{paymentReceived}', [PaymentReceivedController::class, 'destroy'])->name('sales.payments.destroy');
        Route::post('/{paymentReceived}/complete', [PaymentReceivedController::class, 'complete'])->name('sales.payments.complete');
        Route::post('/{paymentReceived}/void', [PaymentReceivedController::class, 'void'])->name('sales.payments.void');
        Route::post('/{paymentReceived}/bounce', [PaymentReceivedController::class, 'bounce'])->name('sales.payments.bounce');
        Route::post('/{paymentReceived}/allocate', [PaymentReceivedController::class, 'allocate'])->name('sales.payments.allocate');
    });
});
