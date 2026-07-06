<?php

return [
    'name' => env('PLATFORM_NAME', 'Я и дом мой'),
    'subtitle' => env('PLATFORM_SUBTITLE', 'Семейная история и память рода'),
    'domains' => [
        'international' => env('PLATFORM_DOMAIN', env('PLATFORM_DOMAIN_COM', 'idommoy.com')),
    ],
    'family_subdomains' => [
        'enabled' => (bool) env('FAMILY_SUBDOMAINS_ENABLED', false),
        'domain' => env('FAMILY_SUBDOMAIN_DOMAIN', env('PLATFORM_DOMAIN', env('PLATFORM_DOMAIN_COM', 'idommoy.com'))),
        'reserved' => [
            'www',
            'admin',
            'api',
            'app',
            'mail',
            'webmail',
            'ftp',
            'ipv4',
            'ipv6',
            'ns1',
            'ns2',
            'ns3',
        ],
    ],
    'locale_domains' => [
        'de' => env('PLATFORM_DOMAIN_DE', 'idommoy.de'),
        'ru' => env('PLATFORM_DOMAIN_RU', 'idommoy.ru'),
        'ru_cyrillic' => env('PLATFORM_DOMAIN_RU_CYRILLIC', 'xn------oddsjtbpd2p.xn--p1acf'),
    ],
    'dormant_tree_days' => (int) env('DORMANT_TREE_DAYS', 365),
    'dormant_warning_days' => (int) env('DORMANT_WARNING_DAYS', 30),
];
