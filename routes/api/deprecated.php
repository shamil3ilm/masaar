<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Deprecated routes
|--------------------------------------------------------------------------
|
| The compliance surface was renamed from regulator names to ISO country codes
| once a second jurisdiction existed: /compliance/zatca became /compliance/sa,
| and /compliance/uae-fta became /compliance/ae. These answer the old paths
| with a 301 and the new location.
|
| Kept in their own file so removing them at v2.0 is deleting one file and one
| require, rather than picking redirect stubs out of the live route tables.
|
*/

Route::middleware(['jwt.auth', 'rate.api'])->group(function () {

    Route::prefix('compliance/zatca')->group(function () {
        Route::any('/{path?}', fn () => response()->json([
            'message' => 'This endpoint has moved. Use /api/compliance/sa/ instead.',
            'docs' => 'https://docs.masaar.sa/migration/v1-to-v2',
        ], 301))->where('path', '.*');
    });

    Route::prefix('compliance/uae-fta')->group(function () {
        Route::any('/{path?}', fn () => response()->json([
            'message' => 'This endpoint has moved. Use /api/compliance/ae/ instead.',
            'docs' => 'https://docs.masaar.sa/migration/v1-to-v2',
        ], 301))->where('path', '.*');
    });
});
