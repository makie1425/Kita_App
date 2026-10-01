<?php

return [
    'path' => env('BACKUP_PATH', storage_path('app/private/backups')),
    'time' => env('BACKUP_TIME', '03:00'),
    'timezone' => env('BACKUP_TIMEZONE', 'Asia/Manila'),
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),
    'max_bytes' => 50 * 1024 * 1024,
];
