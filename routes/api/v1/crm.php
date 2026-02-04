<?php

use App\Http\Controllers\Api\V1\CRM\LeadController;
use App\Http\Controllers\Api\V1\CRM\OpportunityController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CRM API Routes
|--------------------------------------------------------------------------
|
| All routes are prefixed with /api/v1/crm
|
*/

Route::middleware(['auth:api'])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Leads
    |--------------------------------------------------------------------------
    */
    Route::prefix('leads')->group(function () {
        Route::get('/', [LeadController::class, 'index'])->name('crm.leads.index');
        Route::post('/', [LeadController::class, 'store'])->name('crm.leads.store');
        Route::get('/statistics', [LeadController::class, 'statistics'])->name('crm.leads.statistics');
        Route::get('/{lead}', [LeadController::class, 'show'])->name('crm.leads.show');
        Route::put('/{lead}', [LeadController::class, 'update'])->name('crm.leads.update');
        Route::delete('/{lead}', [LeadController::class, 'destroy'])->name('crm.leads.destroy');
        Route::post('/{lead}/status', [LeadController::class, 'changeStatus'])->name('crm.leads.status');
        Route::post('/{lead}/convert', [LeadController::class, 'convert'])->name('crm.leads.convert');
        Route::post('/{lead}/assign', [LeadController::class, 'assign'])->name('crm.leads.assign');
    });

    /*
    |--------------------------------------------------------------------------
    | Opportunities
    |--------------------------------------------------------------------------
    */
    Route::prefix('opportunities')->group(function () {
        Route::get('/', [OpportunityController::class, 'index'])->name('crm.opportunities.index');
        Route::post('/', [OpportunityController::class, 'store'])->name('crm.opportunities.store');
        Route::get('/pipeline', [OpportunityController::class, 'pipeline'])->name('crm.opportunities.pipeline');
        Route::get('/statistics', [OpportunityController::class, 'statistics'])->name('crm.opportunities.statistics');
        Route::get('/forecast', [OpportunityController::class, 'forecast'])->name('crm.opportunities.forecast');
        Route::get('/{opportunity}', [OpportunityController::class, 'show'])->name('crm.opportunities.show');
        Route::put('/{opportunity}', [OpportunityController::class, 'update'])->name('crm.opportunities.update');
        Route::delete('/{opportunity}', [OpportunityController::class, 'destroy'])->name('crm.opportunities.destroy');
        Route::post('/{opportunity}/stage', [OpportunityController::class, 'moveToStage'])->name('crm.opportunities.stage');
        Route::post('/{opportunity}/win', [OpportunityController::class, 'win'])->name('crm.opportunities.win');
        Route::post('/{opportunity}/lose', [OpportunityController::class, 'lose'])->name('crm.opportunities.lose');
        Route::post('/{opportunity}/reopen', [OpportunityController::class, 'reopen'])->name('crm.opportunities.reopen');
    });
});
