<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\WorkspaceRead;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckPresentationAccounts extends Command
{
    protected $signature = 'presentation:check-accounts {admin} {manager}';
    protected $description = 'Read-only verification of presentation account roles and workspace access';

    public function handle(): int
    {
        $accounts = [];
        foreach (['admin', 'manager'] as $role) {
            $email = strtolower(trim($this->argument($role)));
            $matches = User::whereRaw('LOWER(TRIM(email)) = ?', [$email])->get();
            if ($matches->count() !== 1) {
                $this->error("Expected exactly one {$role} account for {$email}. No records changed.");
                return self::FAILURE;
            }
            $user = $matches->first();
            $this->line("{$email}: role={$user->normalizedRole()}; status=".($user->isActive() ? 'Active' : 'Inactive'));
            if ($user->normalizedRole() !== $role || !$user->isActive()) {
                $this->error('Role or active status does not match. No records changed.');
                return self::FAILURE;
            }
            $accounts[$role] = $user;
        }
        $workspace = WorkspaceRead::forUser($accounts['manager']);
        if (!$workspace || !DB::table('workspaces')->where('id', $workspace)->exists()) {
            $this->error('Manager does not have a valid, uniquely owned workspace. No records changed.');
            return self::FAILURE;
        }
        $access = DB::table('workspace_memberships')->where('workspace_id', $workspace)
            ->where('user_id', $accounts['admin']->id)->where('user_role', 'admin')
            ->where('active', true)->where('can_manage_accounts', true)->exists();
        if (!$access) {
            $this->error('Admin does not have active management access to the Manager workspace. No records changed.');
            return self::FAILURE;
        }
        $this->info("Account roles and relationship verified. Manager workspace ID: {$workspace}. No records changed.");
        return self::SUCCESS;
    }
}
