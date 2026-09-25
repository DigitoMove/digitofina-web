<?php

$config = require base_path('vendor/spatie/laravel-backup/config/backup.php');

$config['backup']['source']['files']['include'] = [];
$config['backup']['source']['databases'] = [env('DB_CONNECTION', 'mysql')];
$config['backup']['destination']['disks'] = ['local'];
$config['backup']['password'] = env('BACKUP_ARCHIVE_PASSWORD');
$config['backup']['encryption'] = env('BACKUP_ARCHIVE_PASSWORD') ? 'default' : 'none';
$config['backup']['verify_backup'] = true;
$config['notifications']['mail']['to'] = array_values(array_filter(array_map('trim', explode(',', (string) env('BACKUP_MAIL_TO', '')))));

return $config;
