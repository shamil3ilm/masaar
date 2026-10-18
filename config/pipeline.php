<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Batch submission
    |--------------------------------------------------------------------------
    |
    | POST /api/v1/pipeline/batch takes at most this many invoices. Each is
    | issued and, unless it asks not to be, filed within the one request, so
    | the bound is a request's duration as much as its size: fifty filed one
    | after another is already a long call.
    |
    */

    'batch' => [
        'max_items' => (int) env('PIPELINE_BATCH_MAX_ITEMS', 50),
    ],

];
