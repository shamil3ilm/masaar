<?php

/**
 * The public website: the home page's contact form.
 *
 * Where its messages are sent and what the sender is told back are platform
 * settings an admin edits (Settings, "Public site"). These are the spam
 * guards' limits (ContactGuard) and how long a sender's network details are
 * kept (PruneContactDetails).
 */
return [

    'contact' => [

        // Seconds between opening the page and sending that no person beats:
        // a form filled in faster than this was filled in by a script.
        'min_seconds' => 3,

        // Hours a form stays good after the page was opened. Older, it is
        // refused and the person asked to send it again.
        'max_age_hours' => 24,

        // Messages an hour from one network address, and from one email
        // address, before the form asks the sender to wait.
        'per_ip_per_hour' => 5,
        'per_email_per_hour' => 3,

        // Links one message may carry. Most real messages carry none; link
        // spam carries many.
        'max_links' => 2,

        // Days a sender's network address and browser are kept, for spam
        // review only. After that they are cleared; the message stays.
        'keep_details_days' => 30,

    ],

];
