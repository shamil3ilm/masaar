<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CSV import
|--------------------------------------------------------------------------
|
| Invoices, customers and products brought in through the portal as CSV
| (CsvImports). A file is checked row by row before anything is saved, and
| invoices are saved as drafts, never issued.
|
*/

return [
    // The largest file taken, in kilobytes, and the most rows it may hold.
    'max_kilobytes' => 5120,
    'max_rows' => 5000,

    // A file of up to this many rows is checked while the page waits; a
    // larger one by a queued job, the page saying so until it is done.
    'check_now_rows' => 200,

    // How long an uploaded file is kept before imports:prune removes it.
    'keep_days' => 7,
];
