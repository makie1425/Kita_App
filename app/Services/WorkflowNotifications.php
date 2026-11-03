<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class WorkflowNotifications
{
    public static function reviewers(): array
    {
        $query = User::query();
        if (config('workspaces.enabled')) {
            $workspaceId = WorkspaceRead::id();
            if (! $workspaceId) return [];
            $query->whereExists(function ($membership) use ($workspaceId) {
                $membership->selectRaw('1')->from('workspace_memberships')
                    ->whereColumn('workspace_memberships.user_id', 'users.id')
                    ->whereColumn('workspace_memberships.user_role', 'users.role')
                    ->where('workspace_memberships.workspace_id', $workspaceId)
                    ->where('workspace_memberships.active', true);
            });
        }
        return $query->get()->filter(fn (User $user) => $user->isActive() && $user->normalizedRole() === 'admin')->all();
    }

    public static function owner(?int $id, ?string $role): ?User
    {
        if (! $id || ! $role) return null;
        $query = User::where('id', $id)->where('role', $role);
        if (config('workspaces.enabled')) {
            $workspaceId = WorkspaceRead::id();
            if (! $workspaceId) return null;
            $query->where('workspace_id', $workspaceId);
        }
        return $query->first();
    }

    public static function send(array $recipients, User $actor, string $type, string $message, string $area, ?string $recordId = null, ?int $accountWorkspaceId = null): void
    {
        $recipients = collect($recipients)->filter()->unique(fn (User $user) => $user->role.':'.$user->id);
        $workspaceId = WorkspaceRead::forUser($actor);
        if ($area === 'account' && $accountWorkspaceId !== null) {
            $adminAccountAction = $actor->isActive() && $actor->normalizedRole() === 'super_admin'
                && $recipients->every(fn (User $recipient) => $recipient->normalizedRole() === 'admin'
                    || ($recipient->id === $actor->id && $recipient->normalizedRole() === 'super_admin'));
            abort_unless($adminAccountAction || in_array($accountWorkspaceId, WorkspaceAccess::accountWorkspaceIds($actor), true), 403);
            $workspaceId = $accountWorkspaceId;
        }
        $recipients = $recipients->filter(function (User $recipient) use ($workspaceId, $area) {
            if (! $recipient->isActive() || ($recipient->normalizedRole() === 'super_admin' && $area !== 'account')) return false;
            if (! config('workspaces.enabled')) return true;
            return $workspaceId && DB::table('workspace_memberships')->where('workspace_id', $workspaceId)
                ->where('user_id', $recipient->id)->where('user_role', $recipient->normalizedRole())->where('active', true)->exists();
        });
        if ($recipients->isEmpty()) {
            return;
        }
        // Reuses the existing notification table and manual integer ID convention.
        DB::transaction(function () use ($recipients, $actor, $type, $message, $area, $recordId, $workspaceId) {
            $sequence = DB::table('inventory_sequences')->where('name', 'notifications')->lockForUpdate()->first();
            $id = max((int) $sequence->lastValue, (int) DB::table('notifications')->max('id'));
            foreach ($recipients as $recipient) {
                $role = $recipient->normalizedRole();
                $screen = match ($area) {
                    'purchase' => $role === 'manager' ? 'mgrRequestView' : 'admPurchasedOrders',
                    'receiving' => $role === 'manager' ? 'mgrPurchaseHistory' : 'admReceivingApprovals',
                    'adjustment' => $role === 'manager' ? 'mgrInventory' : 'admInventoryApprovals',
                    'account' => match ($role) { 'super_admin' => 'saAdmins', 'admin' => 'admManagers', 'manager' => 'mgrCashiers', default => null },
                    default => null,
                };
                DB::table('notifications')->insert([
                    'id' => ++$id, 'recipientId' => $recipient->id, 'recipientRole' => $recipient->role,
                    'actorId' => $actor->id, 'actorRole' => $actor->role, 'type' => $type,
                    'message' => $message, 'priority' => 'Standard', 'screen' => $screen,
                    'recordId' => $recordId, 'created_at' => now(),
                    'workspace_id' => $workspaceId,
                ]);
            }
            DB::table('inventory_sequences')->where('name', 'notifications')->update(['lastValue' => $id]);
        });
    }
}
