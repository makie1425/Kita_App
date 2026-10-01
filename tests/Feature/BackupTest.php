<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DatabaseBackup;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['backup.path' => storage_path('framework/testing/backups-'.Str::uuid())]);
    }

    protected function tearDown(): void
    {
        Artisan::call('up');
        File::deleteDirectory(config('backup.path'));
        parent::tearDown();
    }

    public function test_backup_endpoints_require_super_admin(): void
    {
        $this->getJson('/api/backups')->assertUnauthorized();
        foreach (['cashier', 'manager', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/api/backups')->assertForbidden();
            $this->postJson('/api/backups')->assertForbidden();
            $this->getJson('/api/backups/missing/download')->assertForbidden();
        }
    }

    public function test_super_admin_can_create_list_and_download_encrypted_backup(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'superadmin']));
        $id = $this->postJson('/api/backups')->assertCreated()->json('id');
        $this->getJson('/api/backups')->assertOk()->assertJsonPath('backups.0.id', $id);
        $this->get('/api/backups/'.$id.'/download')->assertDownload($id);
        $this->assertStringNotContainsString('tables', File::get(app(DatabaseBackup::class)->path($id)));
        $this->getJson('/api/backups/invalid/download')->assertNotFound();
    }

    public function test_round_trip_restores_changed_and_deleted_records_and_creates_safety_backup(): void
    {
        $user = User::factory()->create(['name' => 'Before backup']);
        $backups = app(DatabaseBackup::class);
        $id = $backups->create();
        $user->update(['name' => 'After backup']);
        User::factory()->create(['name' => 'Extra user']);
        Artisan::call('down');
        $safety = $backups->restore($id);
        $this->assertDatabaseHas('users', ['email' => $user->email, 'name' => 'Before backup']);
        $this->assertDatabaseMissing('users', ['name' => 'Extra user']);
        $this->assertFileExists($backups->path($safety));
        $this->assertCount(2, $backups->history());
    }

    public function test_restore_refuses_online_operation_and_unconfirmed_command(): void
    {
        $this->artisan('backup:restore example')->assertFailed();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('maintenance');
        app(DatabaseBackup::class)->restore('invalid');
    }

    public function test_tampered_backup_leaves_database_unchanged(): void
    {
        $backups = app(DatabaseBackup::class);
        $user = User::factory()->create();
        $id = $backups->create();
        File::put($backups->path($id), 'corrupted');
        Artisan::call('down');
        try {
            $backups->restore($id);
            $this->fail('Corruption should be rejected.');
        } catch (DecryptException $error) {
            $this->assertDatabaseHas('users', ['email' => $user->email]);
        }
    }

    public function test_schema_mismatch_is_rejected_before_replacing_rows(): void
    {
        $backups = app(DatabaseBackup::class);
        $id = $backups->create();
        DB::statement('CREATE TABLE backup_new_table (id INTEGER)');
        Artisan::call('down');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('schema');
        $backups->restore($id);
    }

    public function test_retention_keeps_newest_backup(): void
    {
        $backups = app(DatabaseBackup::class);
        $id = $backups->create();
        touch($backups->path($id), now()->subDays(40)->timestamp);
        $backups->prune();
        $this->assertCount(1, $backups->history());
        $this->travel(1)->seconds();
        $backups->create();
        $backups->prune();
        $this->assertCount(1, $backups->history());
        $this->assertFileDoesNotExist(config('backup.path').'/'.$id);
    }

    public function test_failed_restore_rolls_back_deleted_rows(): void
    {
        $backups = app(DatabaseBackup::class);
        $user = User::factory()->create(['name' => 'Original']);
        $id = $backups->create();
        $payload = json_decode(Crypt::decryptString(File::get($backups->path($id))), true);
        $payload['tables']['users'][0]['email'] = null;
        File::put($backups->path($id), Crypt::encryptString(json_encode($payload)));
        $user->update(['name' => 'Current']);
        Artisan::call('down');
        try {
            $backups->restore($id);
            $this->fail('Invalid rows should fail.');
        } catch (QueryException $error) {
            $this->assertDatabaseHas('users', ['email' => $user->email, 'name' => 'Current']);
            $this->assertCount(2, $backups->history());
        }
    }

    public function test_binary_values_round_trip(): void
    {
        DB::statement('CREATE TABLE backup_binary (id INTEGER PRIMARY KEY, payload BLOB)');
        $binary = "\x00\xff\x80\x01";
        DB::table('backup_binary')->insert(['id' => 1, 'payload' => $binary]);
        $backups = app(DatabaseBackup::class);
        $id = $backups->create();
        DB::table('backup_binary')->delete();
        Artisan::call('down');
        $backups->restore($id);
        $this->assertSame($binary, DB::table('backup_binary')->value('payload'));
    }

    public function test_concurrent_backup_is_rejected_without_recording_success(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'superadmin']));
        $backups = app(DatabaseBackup::class);
        $handle = fopen($backups->directory().'/.lock', 'c');
        flock($handle, LOCK_EX);
        try {
            $this->postJson('/api/backups')->assertStatus(503);
            $this->assertCount(0, $backups->history());
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
