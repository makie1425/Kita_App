<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class ExpiryMonitoring
{
    public static function days(): int
    {
        if (config('workspaces.enabled')) {
            $workspaceId = WorkspaceRead::id();
            return $workspaceId
                ? (int) (DB::table('workspace_expiry_settings')->where('workspace_id', $workspaceId)->value('days') ?? 7)
                : 7;
        }

        return (int) (DB::table('inventory_settings')->where('name', 'expiry_days')->value('value') ?? 7);
    }

    public static function batches(?int $days = null)
    {
        $days ??= self::days();
        return WorkspaceRead::table('inventory_batches as b')->joinSub(WorkspaceRead::table('products'),'p', 'p.id', '=', 'b.productId')
            ->where('b.quantityRemaining', '>', 0)->where('b.recalled', false)
            ->whereNotNull('b.expiryDate')->where('b.expiryDate', '<=', now()->addDays($days)->toDateString())
            ->orderBy('b.expiryDate')->orderBy('b.id')
            ->get(['b.*', 'p.name', 'p.stockUnit']);
    }

    public static function notify(?User $recipient = null): int
    {
        $users = $recipient ? collect([$recipient]) : User::all();
        $users = $users->filter(fn ($u) => $u->isActive() && in_array($u->normalizedRole(), ['manager', 'admin', 'super_admin']));
        if (config('workspaces.enabled')) {
            $users = $users->filter(fn (User $user) => WorkspaceRead::forUser($user) !== null);
        }
        if ($users->isEmpty()) {
            return 0;
        }
        // The shared sequence lock serializes both polling and scheduled delivery.
        return DB::transaction(function () use ($users) {
            $sequence = DB::table('inventory_sequences')->where('name', 'notifications')->lockForUpdate()->first();
            $id = max((int) $sequence->lastValue, (int) DB::table('notifications')->max('id'));
            $count = 0;
            $groups = config('workspaces.enabled')
                ? $users->groupBy(fn (User $user) => WorkspaceRead::forUser($user))
                : collect(['legacy' => $users]);
            foreach ($groups as $workspace => $recipients) {
                $workspaceId = config('workspaces.enabled') ? (int) $workspace : null;
                $days = $workspaceId
                    ? (int) (DB::table('workspace_expiry_settings')->where('workspace_id', $workspaceId)->value('days') ?? 7)
                    : self::days();
                $batches = $workspaceId
                    ? DB::table('inventory_batches as b')->join('products as p', 'p.id', '=', 'b.productId')
                        ->where('b.workspace_id', $workspaceId)->where('p.workspace_id', $workspaceId)
                        ->where('b.quantityRemaining', '>', 0)->where('b.recalled', false)
                        ->whereNotNull('b.expiryDate')->where('b.expiryDate', '<=', now()->addDays($days)->toDateString())
                        ->orderBy('b.expiryDate')->orderBy('b.id')->get(['b.*', 'p.name'])
                    : self::batches($days);
                foreach ($batches as $batch) {
                    $expired = $batch->expiryDate <= now()->toDateString();
                    foreach ($recipients as $user) {
                        $key = implode(':', ['expiry', $workspaceId ?? 'legacy', $batch->id, $batch->expiryDate, $expired ? 'expired' : 'soon', $user->role, $user->id]);
                        if (DB::table('notifications')->where('deduplicationKey', $key)->exists()) continue;
                        DB::table('notifications')->insert([
                            'id' => ++$id, 'recipientId' => $user->id, 'recipientRole' => $user->role,
                            'type' => $expired ? 'Expired stock' : 'Stock expiring soon',
                            'message' => $batch->name.' / batch '.($batch->batchNumber ?: $batch->id).': '.$batch->quantityRemaining.' units, expiry '.$batch->expiryDate.'. '.($expired ? 'Blocked from sale. Arrange pull-out and disposal.' : 'Expires within '.$days.' days. Review this batch.'),
                            'priority' => $expired ? 'Critical' : 'Standard',
                            'screen' => $user->normalizedRole() === 'manager' ? 'mgrInventory' : null,
                            'recordId' => (string) $batch->id, 'created_at' => now(), 'deduplicationKey' => $key,
                            'workspace_id' => $workspaceId,
                        ]);
                        $count++;
                    }
                }
            }
            DB::table('inventory_sequences')->where('name', 'notifications')->update(['lastValue' => $id]);

            return $count;
        });
    }
}
