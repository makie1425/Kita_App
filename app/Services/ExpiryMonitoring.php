<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class ExpiryMonitoring
{
    public static function batches(int $days = 7)
    {
        return DB::table('inventory_batches as b')->join('products as p', 'p.id', '=', 'b.productId')
            ->where('b.quantityRemaining', '>', 0)->where('b.recalled', false)
            ->whereNotNull('b.expiryDate')->where('b.expiryDate', '<=', now()->addDays($days)->toDateString())
            ->orderBy('b.expiryDate')->orderBy('b.id')
            ->get(['b.*', 'p.name', 'p.stockUnit']);
    }

    public static function notify(?User $recipient = null): int
    {
        $users = $recipient ? collect([$recipient]) : User::all();
        $users = $users->filter(fn ($u) => $u->isActive() && in_array($u->normalizedRole(), ['manager', 'admin', 'super_admin']));
        if ($users->isEmpty()) {
            return 0;
        }
        // The shared sequence lock serializes both polling and scheduled delivery.
        return DB::transaction(function () use ($users) {
            $sequence = DB::table('inventory_sequences')->where('name', 'notifications')->lockForUpdate()->first();
            $id = max((int) $sequence->lastValue, (int) DB::table('notifications')->max('id'));
            $count = 0;
            foreach (self::batches() as $batch) {
                $expired = $batch->expiryDate <= now()->toDateString();
                foreach ($users as $user) {
                    $key = implode(':', ['expiry', $batch->id, $batch->expiryDate, $expired ? 'expired' : 'soon', $user->role, $user->id]);
                    if (DB::table('notifications')->where('deduplicationKey', $key)->exists()) {
                        continue;
                    }
                    DB::table('notifications')->insert([
                        'id' => ++$id, 'recipientId' => $user->id, 'recipientRole' => $user->role,
                        'type' => $expired ? 'Expired stock' : 'Stock expiring soon',
                        'message' => $batch->name.' / batch '.($batch->batchNumber ?: $batch->id).': '.$batch->quantityRemaining.' units, expiry '.$batch->expiryDate.'. '.($expired ? 'Blocked from sale. Arrange pull-out and disposal.' : 'Expires within seven days. Review this batch.'),
                        'priority' => $expired ? 'Critical' : 'Standard',
                        'screen' => $user->normalizedRole() === 'manager' ? 'mgrInventory' : null,
                        'recordId' => (string) $batch->id, 'created_at' => now(), 'deduplicationKey' => $key,
                    ]);
                    $count++;
                }
            }
            DB::table('inventory_sequences')->where('name', 'notifications')->update(['lastValue' => $id]);

            return $count;
        });
    }
}
