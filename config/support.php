<?php

/**
 * Support tickets: customers asking for help from inside the portal.
 */
return [

    // Where a new ticket, and a customer's reply to an unassigned one, is
    // announced. Empty: nobody is emailed, and the admin queue is the only
    // place they show.
    'notify_address' => env('SUPPORT_NOTIFY_ADDRESS'),

    // Megabytes of attachments one organization may add to its tickets in
    // a day. A screenshot or an invoice's XML is well under one.
    'daily_upload_mb' => (int) env('SUPPORT_DAILY_UPLOAD_MB', 50),

];
