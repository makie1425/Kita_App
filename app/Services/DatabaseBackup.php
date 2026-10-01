<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class DatabaseBackup
{
    public function directory(): string
    {
        $path = config('backup.path');
        File::ensureDirectoryExists($path, 0700);

        return $path;
    }

    public function path(string $id): string
    {
        abort_unless(preg_match('/^\d{8}_\d{6}_[a-f0-9-]{36}\.backup$/D', $id), 404);
        $path = $this->directory().DIRECTORY_SEPARATOR.$id;
        abort_unless(is_file($path) && ! is_link($path), 404);

        return $path;
    }

    public function history(): array
    {
        $files = glob($this->directory().'/*.backup') ?: [];
        rsort($files);

        return array_map(fn ($path) => ['id' => basename($path), 'created_at' => gmdate('c', filemtime($path)), 'bytes' => filesize($path)], $files);
    }

    private function locked(callable $callback): mixed
    {
        $handle = fopen($this->directory().'/.lock', 'c');
        if (! $handle || ! flock($handle, LOCK_EX | LOCK_NB)) {
            if ($handle) {
                fclose($handle);
            }
            throw new RuntimeException('Another backup or restore is running.');
        }
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function schema(): array
    {
        $db = DB::connection();
        if (! in_array($db->getDriverName(), ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException('Backups support SQLite and MySQL/MariaDB only.');
        }
        $schema = $db->getSchemaBuilder();
        $result = [];
        foreach ($schema->getTables() as $table) {
            if ($db->getDriverName() !== 'sqlite' && ($table['schema'] ?? null) !== $db->getDatabaseName()) {
                continue;
            }
            if (isset($table['engine']) && strtolower($table['engine']) !== 'innodb') {
                throw new RuntimeException('All tables must use InnoDB for consistent backups.');
            }
            $result[$table['name']] = $schema->getColumns($table['name']);
        }
        ksort($result);

        return $result;
    }

    public function create(): string
    {
        return $this->locked(fn () => $this->snapshot());
    }

    private function snapshot(): string
    {
        $db = DB::connection();
        $schema = $this->schema();
        if ($db->transactionLevel() !== 0) {
            throw new RuntimeException('Backup requires a separate transaction.');
        }
        if ($db->getDriverName() !== 'sqlite') {
            $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        $payload = $db->transaction(function () use ($db, $schema) {
            $tables = [];
            $size = 0;
            foreach ($schema as $table => $columns) {
                $tables[$table] = [];
                foreach ($db->table($table)->cursor() as $row) {
                    $encoded = array_map(fn ($value) => $value === null ? null : base64_encode((string) $value), (array) $row);
                    $size += strlen(json_encode($encoded, JSON_THROW_ON_ERROR));
                    if ($size > config('backup.max_bytes')) {
                        throw new RuntimeException('Database exceeds the 50 MB snapshot limit. Use a native database backup.');
                    }
                    $tables[$table][] = $encoded;
                }
            }

            return ['version' => 1, 'driver' => $db->getDriverName(), 'schema' => $schema, 'tables' => $tables];
        });
        $id = now()->utc()->format('Ymd_His').'_'.Str::uuid().'.backup';
        $path = $this->directory().DIRECTORY_SEPARATOR.$id;
        $temporary = $path.'.tmp';
        try {
            $encrypted = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
            if (File::put($temporary, $encrypted) !== strlen($encrypted)) {
                throw new RuntimeException('Could not write the complete backup. Check available storage.');
            }
            chmod($temporary, 0600);
            if (! rename($temporary, $path)) {
                throw new RuntimeException('Could not finalize backup.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return $id;
    }

    public function prune(): void
    {
        $this->locked(function () {
            $history = $this->history();
            foreach (array_slice($history, 1) as $backup) {
                if (filemtime($this->path($backup['id'])) < now()->subDays(max(1, config('backup.retention_days')))->timestamp) {
                    File::delete($this->path($backup['id']));
                }
            }
        });
    }

    public function restore(string $id): string
    {
        if (! app()->isDownForMaintenance()) {
            throw new RuntimeException('Put the application in maintenance mode before restoring.');
        }

        return $this->locked(function () use ($id) {
            $payload = json_decode(Crypt::decryptString(File::get($this->path($id))), true, 512, JSON_THROW_ON_ERROR);
            $db = DB::connection();
            $schema = $this->schema();
            if (($payload['version'] ?? null) !== 1 || ($payload['driver'] ?? null) !== $db->getDriverName() || ($payload['schema'] ?? null) !== $schema || array_keys($payload['tables'] ?? []) !== array_keys($schema)) {
                throw new RuntimeException('Backup schema or database driver does not match. Restore using the matching application version.');
            }
            $safety = $this->snapshot();
            $builder = $db->getSchemaBuilder();
            $builder->disableForeignKeyConstraints();
            try {
                $db->transaction(function () use ($db, $schema, $payload) {
                    foreach (array_keys($schema) as $table) {
                        $db->table($table)->delete();
                    }
                    foreach ($payload['tables'] as $table => $rows) {
                        $generated = array_column(array_filter($schema[$table], fn ($column) => ! empty($column['generation'])), 'name');
                        foreach ($rows as $row) {
                            $row = array_diff_key($row, array_flip($generated));
                            $db->table($table)->insert(array_map(fn ($value) => $value === null ? null : base64_decode($value, true), $row));
                        }
                    }
                    if ($db->getDriverName() === 'sqlite' && $db->select('PRAGMA foreign_key_check')) {
                        throw new RuntimeException('Backup has invalid foreign keys.');
                    }
                    // Do not revive authenticated sessions, OTPs, or pending payment jobs.
                    foreach (['sessions', 'login_otps', 'jobs', 'failed_jobs', 'cache', 'cache_locks'] as $table) {
                        if (isset($schema[$table])) {
                            $db->table($table)->delete();
                        }
                    }
                });
            } finally {
                $builder->enableForeignKeyConstraints();
            }

            return $safety;
        });
    }
}
