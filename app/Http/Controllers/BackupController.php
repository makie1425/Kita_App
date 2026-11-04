<?php

namespace App\Http\Controllers;

use App\Services\DatabaseBackup;
use App\Services\BackupSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class BackupController extends Controller
{
    public function index(DatabaseBackup $backups)
    {
        abort_unless(auth()->user()?->normalizedRole() === 'super_admin', 403);
        $settings = BackupSettings::current();
        return response()->json(['backups' => $backups->history(), 'schedule' => $settings['time'], 'timezone' => $settings['timezone'], 'retention_days' => $settings['retention_days']])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request)
    {
        abort_unless($request->user()?->normalizedRole() === 'super_admin', 403);
        $data = $request->validate([
            'schedule' => ['required', 'date_format:H:i'],
            'retention_days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);
        DB::table('backup_settings')->upsert([[
            'id' => 1,
            'time' => $data['schedule'],
            'retention_days' => (int) $data['retention_days'],
            'updated_at' => now(),
            'created_at' => now(),
        ]], ['id'], ['time', 'retention_days', 'updated_at']);

        return response()->json(BackupSettings::current())->header('Cache-Control', 'no-store');
    }

    public function store(DatabaseBackup $backups)
    {
        abort_unless(auth()->user()?->normalizedRole() === 'super_admin', 403);
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
        abort_unless(auth()->user()?->normalizedRole() === 'super_admin', 403);
        return response()->download($backups->path($id), $id, ['Cache-Control' => 'private, no-store', 'Content-Type' => 'application/octet-stream']);
    }
}
