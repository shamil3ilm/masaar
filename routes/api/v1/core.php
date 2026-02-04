<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Core Module Routes
|--------------------------------------------------------------------------
|
| Routes for organizations, branches, users, roles, and permissions.
| These routes require authentication and organization context.
|
*/

// Organization routes (read-only for regular users)
Route::prefix('organization')->group(function () {
    Route::get('/', function () {
        $organization = auth()->user()->organization;
        return response()->json([
            'success' => true,
            'data' => new \App\Http\Resources\OrganizationResource($organization),
        ]);
    })->name('organization.show');
});

// Branches routes
Route::prefix('branches')->group(function () {
    Route::get('/', function () {
        $branches = auth()->user()->branches;
        return response()->json([
            'success' => true,
            'data' => \App\Http\Resources\BranchResource::collection($branches),
        ]);
    })->name('branches.index');

    Route::post('/{branch}/set-default', function (\App\Models\Core\Branch $branch) {
        auth()->user()->setDefaultBranch($branch);
        return response()->json([
            'success' => true,
            'message' => 'Default branch updated',
        ]);
    })->name('branches.set-default');
});

// Users routes (to be expanded with full CRUD)
Route::prefix('users')->group(function () {
    Route::get('/', function () {
        $users = \App\Models\User::where('organization_id', auth()->user()->organization_id)
            ->with('branches', 'roles')
            ->paginate();

        return response()->json([
            'success' => true,
            'data' => \App\Http\Resources\UserResource::collection($users),
            'meta' => [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    })->middleware('check.permission:core.users.view')->name('users.index');
});

// Roles routes (to be expanded)
Route::prefix('roles')->group(function () {
    Route::get('/', function () {
        $roles = \App\Models\Core\Role::with('permissions')->get();

        return response()->json([
            'success' => true,
            'data' => $roles->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'description' => $role->description,
                'is_system' => $role->is_system,
                'permissions' => $role->permissions->pluck('slug'),
            ]),
        ]);
    })->middleware('check.permission:core.roles.view')->name('roles.index');
});

// Permissions routes
Route::prefix('permissions')->group(function () {
    Route::get('/', function () {
        $permissions = \App\Models\Core\Permission::all()->groupBy('module');

        return response()->json([
            'success' => true,
            'data' => $permissions,
        ]);
    })->middleware('check.permission:core.roles.view')->name('permissions.index');
});

// Settings routes
Route::prefix('settings')->group(function () {
    // Organization settings
    Route::get('/', [\App\Http\Controllers\Api\V1\Core\SettingsController::class, 'index'])
        ->middleware('check.permission:core.settings.view')
        ->name('settings.index');

    Route::get('/definitions', [\App\Http\Controllers\Api\V1\Core\SettingsController::class, 'getDefinitions'])
        ->name('settings.definitions');

    Route::put('/bulk', [\App\Http\Controllers\Api\V1\Core\SettingsController::class, 'updateMany'])
        ->middleware('check.permission:core.settings.edit')
        ->name('settings.update-many');

    Route::get('/group/{group}', [\App\Http\Controllers\Api\V1\Core\SettingsController::class, 'getGroup'])
        ->middleware('check.permission:core.settings.view')
        ->name('settings.group.show');

    Route::put('/group/{group}', [\App\Http\Controllers\Api\V1\Core\SettingsController::class, 'updateGroup'])
        ->middleware('check.permission:core.settings.edit')
        ->name('settings.group.update');

    Route::get('/{key}', [\App\Http\Controllers\Api\V1\Core\SettingsController::class, 'show'])
        ->middleware('check.permission:core.settings.view')
        ->name('settings.show');

    Route::put('/{key}', [\App\Http\Controllers\Api\V1\Core\SettingsController::class, 'update'])
        ->middleware('check.permission:core.settings.edit')
        ->name('settings.update');

    Route::delete('/{key}', [\App\Http\Controllers\Api\V1\Core\SettingsController::class, 'reset'])
        ->middleware('check.permission:core.settings.edit')
        ->name('settings.reset');

    // Cache management
    Route::post('/cache/clear', [\App\Http\Controllers\Api\V1\Core\SettingsController::class, 'clearCache'])
        ->middleware('check.permission:core.settings.edit')
        ->name('settings.cache.clear');
});

// User preferences routes (personal settings)
Route::prefix('preferences')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\V1\Core\SettingsController::class, 'getUserPreferences'])
        ->name('preferences.index');

    Route::put('/bulk', [\App\Http\Controllers\Api\V1\Core\SettingsController::class, 'setUserPreferences'])
        ->name('preferences.update-many');

    Route::get('/{key}', [\App\Http\Controllers\Api\V1\Core\SettingsController::class, 'getUserPreference'])
        ->name('preferences.show');

    Route::put('/{key}', [\App\Http\Controllers\Api\V1\Core\SettingsController::class, 'setUserPreference'])
        ->name('preferences.update');

    Route::delete('/{key}', [\App\Http\Controllers\Api\V1\Core\SettingsController::class, 'deleteUserPreference'])
        ->name('preferences.delete');
});

// Feature flags routes
Route::prefix('features')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\V1\Core\SettingsController::class, 'getFeatures'])
        ->middleware('check.permission:core.settings.view')
        ->name('features.index');

    Route::get('/available', [\App\Http\Controllers\Api\V1\Core\SettingsController::class, 'getAvailableFeatures'])
        ->name('features.available');

    Route::get('/{feature}', [\App\Http\Controllers\Api\V1\Core\SettingsController::class, 'checkFeature'])
        ->name('features.check');

    Route::post('/{feature}/enable', [\App\Http\Controllers\Api\V1\Core\SettingsController::class, 'enableFeature'])
        ->middleware('check.permission:core.settings.edit')
        ->name('features.enable');

    Route::post('/{feature}/disable', [\App\Http\Controllers\Api\V1\Core\SettingsController::class, 'disableFeature'])
        ->middleware('check.permission:core.settings.edit')
        ->name('features.disable');
});

// Number sequences routes
Route::prefix('sequences')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\V1\Core\SettingsController::class, 'getNumberSequences'])
        ->middleware('check.permission:core.settings.view')
        ->name('sequences.index');

    Route::get('/types', [\App\Http\Controllers\Api\V1\Core\SettingsController::class, 'getSequenceTypes'])
        ->name('sequences.types');

    Route::get('/{type}', [\App\Http\Controllers\Api\V1\Core\SettingsController::class, 'getNumberSequence'])
        ->middleware('check.permission:core.settings.view')
        ->name('sequences.show');

    Route::put('/{type}', [\App\Http\Controllers\Api\V1\Core\SettingsController::class, 'updateNumberSequence'])
        ->middleware('check.permission:core.settings.edit')
        ->name('sequences.update');

    Route::get('/{type}/preview', [\App\Http\Controllers\Api\V1\Core\SettingsController::class, 'previewNextNumber'])
        ->name('sequences.preview');
});

// Print routes
Route::prefix('print')->group(function () {
    // Templates management
    Route::get('/templates', [\App\Http\Controllers\Api\V1\Core\PrintController::class, 'templates'])
        ->name('print.templates.index');

    Route::post('/templates', [\App\Http\Controllers\Api\V1\Core\PrintController::class, 'storeTemplate'])
        ->middleware('check.permission:core.settings.edit')
        ->name('print.templates.store');

    Route::get('/templates/{id}', [\App\Http\Controllers\Api\V1\Core\PrintController::class, 'showTemplate'])
        ->name('print.templates.show');

    Route::put('/templates/{id}', [\App\Http\Controllers\Api\V1\Core\PrintController::class, 'updateTemplate'])
        ->middleware('check.permission:core.settings.edit')
        ->name('print.templates.update');

    Route::delete('/templates/{id}', [\App\Http\Controllers\Api\V1\Core\PrintController::class, 'destroyTemplate'])
        ->middleware('check.permission:core.settings.edit')
        ->name('print.templates.destroy');

    Route::post('/templates/initialize', [\App\Http\Controllers\Api\V1\Core\PrintController::class, 'initializeDefaults'])
        ->middleware('check.permission:core.settings.edit')
        ->name('print.templates.initialize');

    // Printer configurations
    Route::get('/configurations', [\App\Http\Controllers\Api\V1\Core\PrintController::class, 'configurations'])
        ->name('print.configurations.index');

    Route::post('/configurations', [\App\Http\Controllers\Api\V1\Core\PrintController::class, 'storeConfiguration'])
        ->middleware('check.permission:core.settings.edit')
        ->name('print.configurations.store');

    Route::put('/configurations/{id}', [\App\Http\Controllers\Api\V1\Core\PrintController::class, 'updateConfiguration'])
        ->middleware('check.permission:core.settings.edit')
        ->name('print.configurations.update');

    // Document printing endpoints
    Route::get('/invoice/{id}', [\App\Http\Controllers\Api\V1\Core\PrintController::class, 'invoice'])
        ->middleware('check.permission:sales.invoices.view')
        ->name('print.invoice');

    Route::get('/quotation/{id}', [\App\Http\Controllers\Api\V1\Core\PrintController::class, 'quotation'])
        ->middleware('check.permission:sales.quotations.view')
        ->name('print.quotation');

    Route::get('/payment-receipt/{id}', [\App\Http\Controllers\Api\V1\Core\PrintController::class, 'paymentReceipt'])
        ->middleware('check.permission:sales.payments.view')
        ->name('print.payment-receipt');

    Route::get('/purchase-order/{id}', [\App\Http\Controllers\Api\V1\Core\PrintController::class, 'purchaseOrder'])
        ->middleware('check.permission:purchase.orders.view')
        ->name('print.purchase-order');

    // Batch printing
    Route::post('/batch', [\App\Http\Controllers\Api\V1\Core\PrintController::class, 'batch'])
        ->name('print.batch');
});

// Localization routes
Route::prefix('localization')->group(function () {
    // Get all localization data for frontend
    Route::get('/', [\App\Http\Controllers\Api\V1\Core\LocalizationController::class, 'index'])
        ->name('localization.index');

    // Languages
    Route::get('/languages', [\App\Http\Controllers\Api\V1\Core\LocalizationController::class, 'languages'])
        ->name('localization.languages');

    // Set user language preference
    Route::post('/language', [\App\Http\Controllers\Api\V1\Core\LocalizationController::class, 'setUserLanguage'])
        ->name('localization.set-language');

    // Translations
    Route::get('/translations/{languageCode}', [\App\Http\Controllers\Api\V1\Core\LocalizationController::class, 'translations'])
        ->name('localization.translations');

    Route::get('/translations/{languageCode}/{group}', [\App\Http\Controllers\Api\V1\Core\LocalizationController::class, 'translationGroup'])
        ->name('localization.translations.group');

    Route::post('/translations', [\App\Http\Controllers\Api\V1\Core\LocalizationController::class, 'updateTranslation'])
        ->middleware('check.permission:core.settings.edit')
        ->name('localization.translations.update');

    Route::post('/translations/bulk', [\App\Http\Controllers\Api\V1\Core\LocalizationController::class, 'updateTranslations'])
        ->middleware('check.permission:core.settings.edit')
        ->name('localization.translations.update-bulk');

    Route::get('/translation-groups', [\App\Http\Controllers\Api\V1\Core\LocalizationController::class, 'translationGroups'])
        ->name('localization.translation-groups');

    // Branding
    Route::get('/branding', [\App\Http\Controllers\Api\V1\Core\LocalizationController::class, 'getBranding'])
        ->name('localization.branding');

    Route::put('/branding', [\App\Http\Controllers\Api\V1\Core\LocalizationController::class, 'updateBranding'])
        ->middleware('check.permission:core.settings.edit')
        ->name('localization.branding.update');

    Route::post('/branding/logo', [\App\Http\Controllers\Api\V1\Core\LocalizationController::class, 'uploadLogo'])
        ->middleware('check.permission:core.settings.edit')
        ->name('localization.branding.upload-logo');
});

// Dashboard routes
Route::prefix('dashboard')->group(function () {
    // Main dashboard data
    Route::get('/', [\App\Http\Controllers\Api\V1\Core\DashboardController::class, 'index'])
        ->name('dashboard.index');

    // Quick stats (combined overview from all modules)
    Route::get('/quick-stats', [\App\Http\Controllers\Api\V1\Core\DashboardController::class, 'quickStats'])
        ->name('dashboard.quick-stats');

    // Available widgets
    Route::get('/widgets', [\App\Http\Controllers\Api\V1\Core\DashboardController::class, 'widgets'])
        ->name('dashboard.widgets');

    // Single widget data
    Route::get('/widgets/{widgetCode}', [\App\Http\Controllers\Api\V1\Core\DashboardController::class, 'widget'])
        ->name('dashboard.widget');

    // Layouts
    Route::get('/layouts', [\App\Http\Controllers\Api\V1\Core\DashboardController::class, 'layouts'])
        ->name('dashboard.layouts');

    Route::post('/layouts', [\App\Http\Controllers\Api\V1\Core\DashboardController::class, 'createLayout'])
        ->name('dashboard.layouts.create');

    Route::get('/layouts/{id}', [\App\Http\Controllers\Api\V1\Core\DashboardController::class, 'layout'])
        ->name('dashboard.layouts.show');

    Route::put('/layouts/{id}', [\App\Http\Controllers\Api\V1\Core\DashboardController::class, 'updateLayout'])
        ->name('dashboard.layouts.update');

    Route::delete('/layouts/{id}', [\App\Http\Controllers\Api\V1\Core\DashboardController::class, 'deleteLayout'])
        ->name('dashboard.layouts.delete');

    // Widget management in layouts
    Route::post('/layouts/{layoutId}/widgets', [\App\Http\Controllers\Api\V1\Core\DashboardController::class, 'addWidget'])
        ->name('dashboard.layouts.add-widget');

    Route::delete('/layouts/{layoutId}/widgets/{widgetCode}', [\App\Http\Controllers\Api\V1\Core\DashboardController::class, 'removeWidget'])
        ->name('dashboard.layouts.remove-widget');

    Route::put('/layouts/{layoutId}/widgets/{widgetCode}/position', [\App\Http\Controllers\Api\V1\Core\DashboardController::class, 'updateWidgetPosition'])
        ->name('dashboard.layouts.widget-position');

    // Reset layout to default
    Route::post('/layouts/{type}/reset', [\App\Http\Controllers\Api\V1\Core\DashboardController::class, 'resetLayout'])
        ->name('dashboard.layouts.reset');
});

// Module Management routes
Route::prefix('modules')->group(function () {
    // Get all available modules and their status
    Route::get('/', [\App\Http\Controllers\Api\V1\Core\ModuleController::class, 'index'])
        ->name('modules.index');

    // Get enabled modules for current user (for navigation)
    Route::get('/user', [\App\Http\Controllers\Api\V1\Core\ModuleController::class, 'userModules'])
        ->name('modules.user');

    // Get module summary for dashboard
    Route::get('/summary', [\App\Http\Controllers\Api\V1\Core\ModuleController::class, 'summary'])
        ->name('modules.summary');

    // Get subscription tiers
    Route::get('/tiers', [\App\Http\Controllers\Api\V1\Core\ModuleController::class, 'tiers'])
        ->name('modules.tiers');

    // Check if module is enabled
    Route::get('/check/{moduleCode}', [\App\Http\Controllers\Api\V1\Core\ModuleController::class, 'check'])
        ->name('modules.check');

    // Check if feature is enabled
    Route::get('/check/{moduleCode}/{feature}', [\App\Http\Controllers\Api\V1\Core\ModuleController::class, 'checkFeature'])
        ->name('modules.check-feature');

    // Enable a module (admin only)
    Route::post('/{moduleCode}/enable', [\App\Http\Controllers\Api\V1\Core\ModuleController::class, 'enable'])
        ->middleware('check.permission:core.settings.edit')
        ->name('modules.enable');

    // Disable a module (admin only)
    Route::post('/{moduleCode}/disable', [\App\Http\Controllers\Api\V1\Core\ModuleController::class, 'disable'])
        ->middleware('check.permission:core.settings.edit')
        ->name('modules.disable');

    // Update module features (admin only)
    Route::put('/{moduleCode}/features', [\App\Http\Controllers\Api\V1\Core\ModuleController::class, 'updateFeatures'])
        ->middleware('check.permission:core.settings.edit')
        ->name('modules.update-features');

    // User-specific module access
    Route::get('/users/{userId}/access', [\App\Http\Controllers\Api\V1\Core\ModuleController::class, 'getUserAccess'])
        ->name('modules.user-access.show');

    Route::put('/users/{userId}/access', [\App\Http\Controllers\Api\V1\Core\ModuleController::class, 'setUserAccess'])
        ->middleware('check.permission:core.users.edit')
        ->name('modules.user-access.update');

    Route::delete('/users/{userId}/access', [\App\Http\Controllers\Api\V1\Core\ModuleController::class, 'clearUserAccess'])
        ->middleware('check.permission:core.users.edit')
        ->name('modules.user-access.clear');
});

// Notification routes
Route::prefix('notifications')->group(function () {
    // Get user notifications
    Route::get('/', [\App\Http\Controllers\Api\V1\Core\NotificationController::class, 'index'])
        ->name('notifications.index');

    // Get unread count
    Route::get('/unread-count', [\App\Http\Controllers\Api\V1\Core\NotificationController::class, 'unreadCount'])
        ->name('notifications.unread-count');

    // Get notification types
    Route::get('/types', [\App\Http\Controllers\Api\V1\Core\NotificationController::class, 'types'])
        ->name('notifications.types');

    // Mark all as read
    Route::post('/mark-all-read', [\App\Http\Controllers\Api\V1\Core\NotificationController::class, 'markAllAsRead'])
        ->name('notifications.mark-all-read');

    // Notification preferences
    Route::get('/preferences', [\App\Http\Controllers\Api\V1\Core\NotificationController::class, 'preferences'])
        ->name('notifications.preferences');

    Route::put('/preferences', [\App\Http\Controllers\Api\V1\Core\NotificationController::class, 'updatePreferences'])
        ->name('notifications.preferences.update');

    Route::put('/preferences/{type}', [\App\Http\Controllers\Api\V1\Core\NotificationController::class, 'updatePreference'])
        ->name('notifications.preferences.update-single');

    Route::post('/preferences/initialize', [\App\Http\Controllers\Api\V1\Core\NotificationController::class, 'initializePreferences'])
        ->name('notifications.preferences.initialize');

    // Single notification actions
    Route::post('/{id}/read', [\App\Http\Controllers\Api\V1\Core\NotificationController::class, 'markAsRead'])
        ->name('notifications.mark-read');

    Route::delete('/{id}', [\App\Http\Controllers\Api\V1\Core\NotificationController::class, 'destroy'])
        ->name('notifications.destroy');
});

// Import routes
Route::prefix('imports')->group(function () {
    // Get available entity types for import
    Route::get('/entity-types', [\App\Http\Controllers\Api\V1\Core\ImportController::class, 'entityTypes'])
        ->name('imports.entity-types');

    // Get import history
    Route::get('/history', [\App\Http\Controllers\Api\V1\Core\ImportController::class, 'history'])
        ->name('imports.history');

    // Download sample import template
    Route::get('/sample/{entityType}', [\App\Http\Controllers\Api\V1\Core\ImportController::class, 'sampleTemplate'])
        ->name('imports.sample-template');

    // Import templates management
    Route::get('/templates', [\App\Http\Controllers\Api\V1\Core\ImportController::class, 'templates'])
        ->name('imports.templates.index');

    Route::post('/templates', [\App\Http\Controllers\Api\V1\Core\ImportController::class, 'saveTemplate'])
        ->name('imports.templates.store');

    // Upload file for import
    Route::post('/upload', [\App\Http\Controllers\Api\V1\Core\ImportController::class, 'upload'])
        ->name('imports.upload');

    // Import job operations
    Route::get('/{uuid}', [\App\Http\Controllers\Api\V1\Core\ImportController::class, 'status'])
        ->name('imports.status');

    Route::get('/{uuid}/preview', [\App\Http\Controllers\Api\V1\Core\ImportController::class, 'preview'])
        ->name('imports.preview');

    Route::post('/{uuid}/configure', [\App\Http\Controllers\Api\V1\Core\ImportController::class, 'configure'])
        ->name('imports.configure');

    Route::post('/{uuid}/process', [\App\Http\Controllers\Api\V1\Core\ImportController::class, 'process'])
        ->name('imports.process');

    Route::post('/{uuid}/cancel', [\App\Http\Controllers\Api\V1\Core\ImportController::class, 'cancel'])
        ->name('imports.cancel');
});

// Export routes
Route::prefix('exports')->group(function () {
    // Get available entity types for export
    Route::get('/entity-types', [\App\Http\Controllers\Api\V1\Core\ExportController::class, 'entityTypes'])
        ->name('exports.entity-types');

    // Get export history
    Route::get('/history', [\App\Http\Controllers\Api\V1\Core\ExportController::class, 'history'])
        ->name('exports.history');

    // Create export job
    Route::post('/', [\App\Http\Controllers\Api\V1\Core\ExportController::class, 'create'])
        ->name('exports.create');

    // Quick export (immediate download)
    Route::post('/quick', [\App\Http\Controllers\Api\V1\Core\ExportController::class, 'quickExport'])
        ->name('exports.quick');

    // Export job operations
    Route::get('/{uuid}', [\App\Http\Controllers\Api\V1\Core\ExportController::class, 'status'])
        ->name('exports.status');

    Route::get('/{uuid}/download', [\App\Http\Controllers\Api\V1\Core\ExportController::class, 'download'])
        ->name('api.v1.exports.download');
});

// Webhook routes
Route::prefix('webhooks')->group(function () {
    // Get available webhook events
    Route::get('/events', [\App\Http\Controllers\Api\V1\Core\WebhookController::class, 'events'])
        ->name('webhooks.events');

    // Get recent event history
    Route::get('/events/history', [\App\Http\Controllers\Api\V1\Core\WebhookController::class, 'events_history'])
        ->name('webhooks.events.history');

    // List webhooks
    Route::get('/', [\App\Http\Controllers\Api\V1\Core\WebhookController::class, 'index'])
        ->middleware('check.permission:core.settings.view')
        ->name('webhooks.index');

    // Create webhook
    Route::post('/', [\App\Http\Controllers\Api\V1\Core\WebhookController::class, 'store'])
        ->middleware('check.permission:core.settings.edit')
        ->name('webhooks.store');

    // Webhook operations
    Route::get('/{id}', [\App\Http\Controllers\Api\V1\Core\WebhookController::class, 'show'])
        ->middleware('check.permission:core.settings.view')
        ->name('webhooks.show');

    Route::put('/{id}', [\App\Http\Controllers\Api\V1\Core\WebhookController::class, 'update'])
        ->middleware('check.permission:core.settings.edit')
        ->name('webhooks.update');

    Route::delete('/{id}', [\App\Http\Controllers\Api\V1\Core\WebhookController::class, 'destroy'])
        ->middleware('check.permission:core.settings.edit')
        ->name('webhooks.destroy');

    // Test webhook
    Route::post('/{id}/test', [\App\Http\Controllers\Api\V1\Core\WebhookController::class, 'test'])
        ->middleware('check.permission:core.settings.edit')
        ->name('webhooks.test');

    // Toggle webhook status
    Route::post('/{id}/toggle', [\App\Http\Controllers\Api\V1\Core\WebhookController::class, 'toggle'])
        ->middleware('check.permission:core.settings.edit')
        ->name('webhooks.toggle');

    // Regenerate secret
    Route::post('/{id}/regenerate-secret', [\App\Http\Controllers\Api\V1\Core\WebhookController::class, 'regenerateSecret'])
        ->middleware('check.permission:core.settings.edit')
        ->name('webhooks.regenerate-secret');

    // Delivery history
    Route::get('/{id}/deliveries', [\App\Http\Controllers\Api\V1\Core\WebhookController::class, 'deliveries'])
        ->middleware('check.permission:core.settings.view')
        ->name('webhooks.deliveries');

    Route::get('/{id}/deliveries/{deliveryId}', [\App\Http\Controllers\Api\V1\Core\WebhookController::class, 'deliveryDetails'])
        ->middleware('check.permission:core.settings.view')
        ->name('webhooks.deliveries.show');

    Route::post('/{id}/deliveries/{deliveryId}/retry', [\App\Http\Controllers\Api\V1\Core\WebhookController::class, 'retryDelivery'])
        ->middleware('check.permission:core.settings.edit')
        ->name('webhooks.deliveries.retry');
});
