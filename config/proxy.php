<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Stream Proxy Encryption
    |--------------------------------------------------------------------------
    |
    | PROXY_ENCRYPTION_KEY: 64-character hex string (32 bytes for AES-256).
    | PROXY_IV:             32-character hex string (16 bytes, AES block size).
    |
    | Both are static so the same URL always produces the same encrypted string.
    | Generate with: openssl rand -hex 32   (key)
    |                openssl rand -hex 16   (iv)
    |
    */

    'encryption_key' => env('PROXY_ENCRYPTION_KEY'),
    'iv'             => env('PROXY_IV'),
];
