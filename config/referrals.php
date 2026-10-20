<?php

/**
 * The referral programme. Its terms - whether it runs, the rewards, the
 * qualifying event and the caps - are set in Admin › Finance › Referrals;
 * this is what the abuse checks need beside them.
 */
return [

    // Public mail providers: two people on one of these share a domain and
    // nothing else, so the same-domain check passes them by.
    'public_email_domains' => [
        'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com',
        'yahoo.com', 'icloud.com', 'me.com', 'aol.com', 'proton.me', 'protonmail.com', 'zoho.com',
    ],

];
