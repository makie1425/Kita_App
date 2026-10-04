<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockMovement
{
    public static function record(int $productId, int $before, int $after, string $type, string $referenceId, ?User $user = null): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Stock movements and FIFO allocations must share a database transaction.');
        }
        FifoInventory::opening($productId, $before);
        if ($after < $before) {
            FifoInventory::consume($productId, $before - $after, $type, $referenceId);
        } elseif ($after > $before && in_array($type, ['refund', 'void', 'exchange', 'payment_release'], true)) {
            FifoInventory::returnStock($productId, $after - $before, $referenceId);
        } elseif ($after > $before && $type !== 'purchase_receiving') {
            throw ValidationException::withMessages(['stock' => 'New stock must be recorded through purchase receiving.']);
        }
        if ((int) DB::table('inventory_batches')->where('productId', $productId)->sum('quantityRemaining') !== $after) {
            throw ValidationException::withMessages(['stock' => 'Inventory batch balance differs from available stock. Reconcile before continuing.']);
        }
        DB::table('stock_movements')->insert([
            'productId' => $productId, 'quantityChange' => $after - $before,
            'quantityBefore' => $before, 'quantityAfter' => $after,
            'referenceType' => $type, 'referenceId' => $referenceId,
            'userId' => $user?->id, 'userRole' => $user?->role, 'created_at' => now(),
        ]);
    }
}
