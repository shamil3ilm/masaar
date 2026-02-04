<?php

use App\Http\Controllers\Api\V1\Purchase\BillController;
use App\Http\Controllers\Api\V1\Purchase\PaymentMadeController;
use App\Http\Controllers\Api\V1\Purchase\PurchaseOrderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Purchase API Routes
|--------------------------------------------------------------------------
|
| All routes are prefixed with /api/v1/purchase
|
*/

Route::middleware(['auth:api'])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Purchase Orders
    |--------------------------------------------------------------------------
    */
    Route::prefix('purchase-orders')->group(function () {
        Route::get('/', [PurchaseOrderController::class, 'index'])->name('purchase.orders.index');
        Route::post('/', [PurchaseOrderController::class, 'store'])->name('purchase.orders.store');
        Route::get('/summary', [PurchaseOrderController::class, 'summary'])->name('purchase.orders.summary');
        Route::get('/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->name('purchase.orders.show');
        Route::put('/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->name('purchase.orders.update');
        Route::delete('/{purchaseOrder}', [PurchaseOrderController::class, 'destroy'])->name('purchase.orders.destroy');
        Route::post('/{purchaseOrder}/send', [PurchaseOrderController::class, 'send'])->name('purchase.orders.send');
        Route::post('/{purchaseOrder}/confirm', [PurchaseOrderController::class, 'confirm'])->name('purchase.orders.confirm');
        Route::post('/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase.orders.cancel');
        Route::post('/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])->name('purchase.orders.receive');
        Route::post('/{purchaseOrder}/duplicate', [PurchaseOrderController::class, 'duplicate'])->name('purchase.orders.duplicate');
    });

    /*
    |--------------------------------------------------------------------------
    | Bills (Supplier Invoices)
    |--------------------------------------------------------------------------
    */
    Route::prefix('bills')->group(function () {
        Route::get('/', [BillController::class, 'index'])->name('purchase.bills.index');
        Route::post('/', [BillController::class, 'store'])->name('purchase.bills.store');
        Route::get('/summary', [BillController::class, 'summary'])->name('purchase.bills.summary');
        Route::post('/from-purchase-order', [BillController::class, 'createFromPurchaseOrder'])->name('purchase.bills.from-po');
        Route::get('/{bill}', [BillController::class, 'show'])->name('purchase.bills.show');
        Route::put('/{bill}', [BillController::class, 'update'])->name('purchase.bills.update');
        Route::delete('/{bill}', [BillController::class, 'destroy'])->name('purchase.bills.destroy');
        Route::post('/{bill}/approve', [BillController::class, 'approve'])->name('purchase.bills.approve');
        Route::post('/{bill}/void', [BillController::class, 'void'])->name('purchase.bills.void');
    });

    /*
    |--------------------------------------------------------------------------
    | Payments Made
    |--------------------------------------------------------------------------
    */
    Route::prefix('payments-made')->group(function () {
        Route::get('/', [PaymentMadeController::class, 'index'])->name('purchase.payments.index');
        Route::post('/', [PaymentMadeController::class, 'store'])->name('purchase.payments.store');
        Route::get('/summary', [PaymentMadeController::class, 'summary'])->name('purchase.payments.summary');
        Route::get('/supplier-statement', [PaymentMadeController::class, 'supplierStatement'])->name('purchase.payments.supplier-statement');
        Route::get('/{paymentMade}', [PaymentMadeController::class, 'show'])->name('purchase.payments.show');
        Route::delete('/{paymentMade}', [PaymentMadeController::class, 'destroy'])->name('purchase.payments.destroy');
        Route::post('/{paymentMade}/complete', [PaymentMadeController::class, 'complete'])->name('purchase.payments.complete');
        Route::post('/{paymentMade}/void', [PaymentMadeController::class, 'void'])->name('purchase.payments.void');
        Route::post('/{paymentMade}/allocate', [PaymentMadeController::class, 'allocate'])->name('purchase.payments.allocate');
    });
});
