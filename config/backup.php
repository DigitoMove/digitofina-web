<?php

$packageConfig = base_path('vendor/spatie/laravel-backup/config/backup.php');

if (! is_file($packageConfig)) {
    return [
        'backup' => [
            'source' => ['files' => ['include' => []], 'databases' => [env('DB_CONNECTION', 'mysql')]],
            'destination' => ['disks' => ['local']],
        ],
        'notifications' => ['mail' => ['to' => []]],
    ];
}

$config = require $packageConfig;

$config['backup']['source']['files']['include'] = [];
$config['backup']['source']['databases'] = [env('DB_CONNECTION', 'mysql')];
$config['backup']['destination']['disks'] = ['local'];
$config['backup']['password'] = env('BACKUP_ARCHIVE_PASSWORD');
$config['backup']['encryption'] = env('BACKUP_ARCHIVE_PASSWORD') ? 'default' : 'none';
$config['backup']['verify_backup'] = true;
$config['notifications']['mail']['to'] = array_values(array_filter(array_map('trim', explode(',', (string) env('BACKUP_MAIL_TO', '')))));
$config['mail_delivery'] = [
    'enabled' => (bool) env('BACKUP_EMAIL_ENABLED', false),
    'mailer' => env('BACKUP_MAILER', env('MAIL_MAILER', 'log')),
    'from' => env('BACKUP_MAIL_FROM_ADDRESS', env('MAIL_FROM_ADDRESS', 'hello@example.com')),
];

return $config;
