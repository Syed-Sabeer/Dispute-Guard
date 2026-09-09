<?php

return [
    // Null preserves existing installations' operator-controlled MAIL_FROM_ADDRESS.
    'managed_address' => env('MANAGED_SENDER_ADDRESS'),
    'managed_domain' => env('MANAGED_SENDER_DOMAIN'),
    'verification_ttl_minutes' => 15,
];
