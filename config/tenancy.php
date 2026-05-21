<?php

return [
    'root_domain' => env('TENANCY_ROOT_DOMAIN', 'gateoffina.com'),

    'central_domains' => array_filter(array_map(
        'trim',
        explode(',', env('TENANCY_CENTRAL_DOMAINS', 'gateoffina.com,app.gateoffina.com,localhost,127.0.0.1'))
    )),
];
