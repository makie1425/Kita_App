<?php

use App\Models\User;
use App\Services\DatabaseBackup;
use App\Services\BackupSettings;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;

Artisan::command('inventory:expiry-alerts', function () {
    $this->info('Expiry notifications created: '.\App\Services\ExpiryMonitoring::notify());
})->purpose('Notify managers and administrators about expired stock and stock expiring within seven days');
Schedule::command('inventory:expiry-alerts')->hourly()->withoutOverlapping();

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
    ->everyMinute()->when(function () {
        $settings = BackupSettings::current();
        return now($settings['timezone'])->format('H:i') === $settings['time'];
    })->withoutOverlapping();

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

Artisan::command('workspaces:assignment-preview {owner}', function () {
    $identity=strtolower(trim($this->argument('owner')));
    $matches=User::query()->where(fn($q)=>$q->whereRaw('LOWER(email) = ?',[$identity])->orWhereRaw('LOWER(username) = ?',[$identity]))->get();
    if($matches->count()!==1 || !$matches->first()->isActive() || $matches->first()->normalizedRole()!=='manager'){
        $this->error('An exact, unique, active Manager email or username is required. No records changed.');return 1;
    }
    $owner=$matches->first();
    $this->info('Proposed owner: '.$owner->email.' (manager ID '.$owner->id.'). Preview only.');
    $rows=[];
    foreach(\App\Services\WorkspaceTables::BUSINESS as $table){
        $rows[]=[$table,DB::table($table)->whereNull('workspace_id')->count(),DB::table($table)->whereNotNull('workspace_id')->count()];
    }
    $this->table(['Table','Unassigned records','Already assigned records'],$rows);
    $this->warn('No changes made. Cashier ownership and administrative grants require explicit assignments.');
})->purpose('Preview legacy business ownership without changing records or granting account access');

Artisan::command('workspaces:assign-legacy {owner} {--admins=} {--superadmins=} {--cashiers=} {--apply}', function () {
    if ($this->option('apply')) {
        $this->error('Bulk legacy assignment is disabled. Review and map each historical record to its verified owner manually, then audit the result. No records changed.');

        return 1;
    }
    $resolve=function(string $email,string $expectedRole){
        $matches=User::query()->whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim($email))])->get();
        if($matches->count()!==1 || !$matches->first()->isActive() || $matches->first()->normalizedRole()!==$expectedRole) throw new RuntimeException('Identity/role check failed for '.$email.'. No assignment applied.');
        return $matches->first();
    };
    try {
        $owner=$resolve($this->argument('owner'),'manager');
        $admins=array_filter(array_map('trim',explode(',',(string)$this->option('admins'))));
        $supers=array_filter(array_map('trim',explode(',',(string)$this->option('superadmins'))));
        $cashiers=array_filter(array_map('trim',explode(',',(string)$this->option('cashiers'))));
        $assignments=collect();
        foreach($admins as $email)$assignments->push([$resolve($email,'admin'),'admin',true]);
        foreach($supers as $email)$assignments->push([$resolve($email,'super_admin'),'super_admin',true]);
        foreach($cashiers as $email)$assignments->push([$resolve($email,'cashier'),'cashier',false]);
        if($assignments->pluck(0)->pluck('email')->unique()->count()!==$assignments->count()) throw new RuntimeException('Duplicate account assignment provided.');
        $workspaceId=$owner->workspace_id;
        if($workspaceId && DB::table('workspaces')->where('id',$workspaceId)->value('name')!=='KITA Business') throw new RuntimeException('Manager already has a different workspace assignment.');
        $this->info('Manager: '.$owner->name.' <'.$owner->email.'>');
        $this->info('Explicit account grants:');
        foreach($assignments as [$user,$role,$manage])$this->line('- '.$role.': '.$user->name.' <'.$user->email.'>; account management: '.($manage?'yes':'no'));
        $this->info('Business records currently unassigned:');
        foreach(\App\Services\WorkspaceTables::BUSINESS as $table){$count=DB::table($table)->whereNull('workspace_id')->count();if($count)$this->line('- '.$table.': '.$count);}
        $this->warn('Preview only. Bulk --apply is disabled; review and map historical records individually with an audit trail.');
        return 0;
    } catch(\Throwable $e) {$this->error($e->getMessage());return 1;}
})->purpose('Preview legacy ownership; bulk assignment is permanently disabled');
