<?php

namespace App\Http\Controllers;

use App\Services\DatabaseBackup;
use Throwable;

class BackupController extends Controller
{
    public function index(DatabaseBackup $backups)
    {
        return response()->json(['backups' => $backups->history(), 'schedule' => config('backup.time'), 'timezone' => config('backup.timezone'), 'retention_days' => config('backup.retention_days')])->header('Cache-Control', 'no-store');
    }

    public function store(DatabaseBackup $backups)
    {
        try {
            $id = $backups->create();
        } catch (Throwable $error) {
            report($error);

            return response()->json(['message' => 'Backup failed. Check the server logs; no completed backup was recorded.'], 503);
        }

        return response()->json(['id' => $id], 201);
    }

    public function download(string $id, DatabaseBackup $backups)
    {
        return response()->download($backups->path($id), $id, ['Cache-Control' => 'private, no-store', 'Content-Type' => 'application/octet-stream']);
    }
}
