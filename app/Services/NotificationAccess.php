<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class NotificationAccess
{
    public static function owned(User $user): Builder
    {
        $query = DB::table('notifications')->where('recipientId', $user->id)->where('recipientRole', $user->role);
        if (config('workspaces.enabled')) {
            $workspaceId = WorkspaceRead::forUser($user);
            if (! $workspaceId) return $query->whereRaw('1 = 0');
            $query->where('workspace_id', $workspaceId);
        }
        if ($user->normalizedRole() === 'super_admin') $query->where('screen', 'saAdmins');

        return $query;
    }
}
