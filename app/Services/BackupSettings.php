<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class BackupSettings
{
    public static function current(): array
    {
        $saved = DB::table('backup_settings')->where('id', 1)->first();

        return [
            'time' => $saved?->time ?? config('backup.time'),
            'timezone' => config('backup.timezone'),
            'retention_days' => (int) ($saved?->retention_days ?? config('backup.retention_days')),
        ];
    }
}
