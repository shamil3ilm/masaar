<?php

/**
 * Support tickets: customers asking for help from inside the portal.
 */
return [

    // Where a new ticket, and a customer's reply to an unassigned one, is
    // announced. Empty: nobody is emailed, and the admin queue is the only
    // place they show.
    'notify_address' => env('SUPPORT_NOTIFY_ADDRESS'),

];
