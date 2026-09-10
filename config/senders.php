<?php

return [
    // System verification / operator diagnostics ONLY. Never used by customer or test automation.
    // Legacy variable names retained so production environment files need not change.
    'managed_address' => env('MANAGED_SENDER_ADDRESS'),
    'managed_domain' => env('MANAGED_SENDER_DOMAIN'),
    'verification_ttl_minutes' => 15,
];
