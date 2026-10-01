<?php

use App\Models\User;
use App\Services\DatabaseBackup;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;

Artisan::command('backup:run', function (DatabaseBackup $backups) {
    $this->info('Backup created: '.$backups->create());
    $backups->prune();
})->purpose('Create an encrypted database snapshot and prune expired backups');

Artisan::command('backup:restore {id} {--confirm=}', function (DatabaseBackup $backups) {
    if ($this->option('confirm') !== $this->argument('id')) {
        $this->error('Restore replaces current data. Pass --confirm with the exact backup filename.');

        return 1;
    }
    $safety = $backups->restore($this->argument('id'));
    $this->info('Database restored. Safety backup: '.$safety.'. Verify data before running artisan up.');
})->purpose('Restore a matching database snapshot in maintenance mode');

Schedule::command('backup:run')
    ->dailyAt(config('backup.time'))->timezone(config('backup.timezone'))->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('accounts:superadmin {email} {--name=Super Admin}', function () {
    $email = strtolower(trim($this->argument('email')));
    if (User::all()->contains(fn ($user) => $user->normalizedRole() === 'super_admin' && $user->isActive())) {
        $this->error('An active Super Admin already exists. Manage accounts through that account.');

        return 1;
    }
    $password = $this->secret('Password (minimum 8 characters)');
    $confirmation = $this->secret('Confirm password');
    $data = Validator::make([
        'email' => $email, 'name' => $this->option('name'), 'password' => $password, 'password_confirmation' => $confirmation,
    ], ['email' => ['required', 'email', 'unique:users,email'], 'name' => ['required', 'string', 'max:120'],
        'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed']])->validate();
    DB::transaction(function () use ($data) {
        $counter = DB::table('account_sequences')->where('name', 'users')->lockForUpdate()->first();
        if (User::all()->contains(fn ($user) => $user->normalizedRole() === 'super_admin' && $user->isActive())) {
            throw new RuntimeException('An active Super Admin already exists.');
        }
        $id = max($counter->next_id, (int) DB::table('users')->max('id') + 1);
        DB::table('users')->insert(['id' => $id, 'role' => 'superadmin', 'name' => $data['name'],
            'email' => $data['email'], 'password' => Hash::make($data['password']),
            'status' => 'Active', 'createdBy' => 'Initial setup', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('account_sequences')->where('name', 'users')->update(['next_id' => $id + 1]);
    });
    $this->info('Super Admin created. Sign in through the shared login page.');

    return 0;
})->purpose('Provision the first Super Admin without a default password');
