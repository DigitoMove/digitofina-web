<?php

return [
    'enabled' => env('TRAFFIC_ENABLED', true),
    'root_domains' => array_filter(array_map('trim', explode(',', env('TRAFFIC_ROOT_DOMAINS', '')))),
];
