<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class WorkspaceProvisioning
{
    public static function createdAccount(User $actor, User $account): void
    {
        if (!config('workspaces.enabled')) return;
        $parent = WorkspaceAccess::requireAccountWorkspace($actor);
        $workspace = $parent;
        if ($account->normalizedRole() === 'manager') {
            $organization = DB::table('workspaces')->where('id', $parent)->value('organization_id');
            $workspace = DB::table('workspaces')->insertGetId([
                'organization_id' => $organization, 'name' => $account->name.' workspace',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            // Only the administrator creating this workspace receives its management grant.
            self::grant($actor, $workspace, true);
        }
        $ownership = ['workspace_id' => $workspace];
        if ($account->normalizedRole() === 'cashier') {
            $managerId = WorkspaceRead::managerId($workspace);
            abort_unless($managerId, 403, 'Select a workspace with one active assigned Manager before creating a Cashier.');
            $ownership += ['assigned_manager_id' => $managerId, 'assigned_manager_role' => 'manager'];
        }
        $account->forceFill($ownership)->save();
        self::grant($account, $workspace, $account->normalizedRole() !== 'cashier');
    }

    private static function grant(User $user, int $workspace, bool $manage): void
    {
        DB::table('workspace_memberships')->updateOrInsert([
            'workspace_id' => $workspace, 'user_id' => $user->id, 'user_role' => $user->normalizedRole(),
        ], ['active' => true, 'can_manage_accounts' => $manage, 'created_at' => now(), 'updated_at' => now()]);
    }
}
