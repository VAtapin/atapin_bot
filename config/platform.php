<?php

return [
    'name' => env('PLATFORM_NAME', 'Я и дом мой'),
    'subtitle' => env('PLATFORM_SUBTITLE', 'Семейная история и память рода'),
    'domains' => [
        'international' => env('PLATFORM_DOMAIN_COM', 'idommoy.com'),
    ],
    'dormant_tree_days' => (int) env('DORMANT_TREE_DAYS', 365),
    'dormant_warning_days' => (int) env('DORMANT_WARNING_DAYS', 30),
    'require_owner_two_factor' => (bool) env('PLATFORM_REQUIRE_OWNER_TWO_FACTOR', true),
];
