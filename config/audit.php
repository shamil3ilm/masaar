<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Audit chain seal
    |--------------------------------------------------------------------------
    |
    | The key that seals each new audit entry and each chain's head with a
    | keyed hash (AuditChain::seal), so the audit log cannot be rewritten by
    | someone who holds the database but not this key. Kept out of the
    | database and apart from APP_KEY, which is refused here. Empty: entries
    | carry their plain hash only, as before. Previous keys still verify the
    | seals they made, after a rotation. Generate one: openssl rand -base64 32
    |
    */

    'chain_key' => env('AUDIT_CHAIN_KEY'),

    'chain_previous_keys' => array_values(array_filter(array_map('trim', explode(',', (string) env('AUDIT_CHAIN_PREVIOUS_KEYS', ''))))),

];
