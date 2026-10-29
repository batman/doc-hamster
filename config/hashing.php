<?php

declare(strict_types=1);

/*
| Only tax advisor accounts have a password (D-012); it is hashed with
| Argon2id. Owners and members sign in with passkeys and have none. All other
| hashing options keep Laravel's defaults (memory 64 MB, time 4).
*/
return [

    'driver' => env('HASH_DRIVER', 'argon2id'),

];
