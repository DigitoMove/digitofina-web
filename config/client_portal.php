<?php

return [
    'default_temporary_password' => env('CLIENT_PORTAL_DEFAULT_PASSWORD', '12345678'),

    'domains' => array_filter(array_map(
        'trim',
        explode(',', env('CLIENT_PORTAL_DOMAINS', 'clients.*'))
    )),
];
