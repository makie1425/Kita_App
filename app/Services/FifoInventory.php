<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FifoInventory
{
    public static function opening(int $productId, int $before): void
    {
        // Legacy/imported products without a batch need an explicit opening balance.
        if ($before > 0 && ! DB::table('inventory_batches')->where('productId', $productId)->exists()) {
            $product = DB::table('products')->where('id', $productId)->first();
            self::receive($productId, $before, max(0, (float) $product->unitPrice), '1970-01-01', null, null, 'legacy_opening', expiry: $product->expiry);
        }
    }

    public static function receive(int $id, int $qty, float $cost, string $date, ?string $receipt = null, ?int $supplier = null, string $source = 'purchase_receiving', ?string $batch = null, ?string $expiry = null, ?string $manufactured = null, ?string $lot = null): void
    {
        DB::table('inventory_batches')->insert(['productId' => $id, 'quantityReceived' => $qty, 'quantityRemaining' => $qty,
            'unitCost' => $cost, 'receivedDate' => $date, 'receiptId' => $receipt, 'supplierId' => $supplier, 'source' => $source,
            'manufacturer'=>DB::table('products')->where('id',$id)->value('manufacturer'), 'batchNumber' => $batch, 'expiryDate' => $expiry, 'manufacturedDate' => $manufactured, 'lotNumber' => $lot, 'created_at' => now()]);
    }

    public static function consume(int $id, int $qty, string $type, string $reference): void
    {
        $query = DB::table('inventory_batches')->where('productId', $id)->where('quantityRemaining', '>', 0);
        if (in_array($type, ['checkout', 'payment_reservation', 'exchange_replacement'], true)) {
            $query->where('recalled', false)->where(fn ($q) => $q->whereNull('expiryDate')->orWhere('expiryDate', '>', now()->toDateString()));
        }
        $batches = $query->orderBy('receivedDate')->orderBy('id')->lockForUpdate()->get();
        if ($batches->sum('quantityRemaining') < $qty) {
            throw ValidationException::withMessages(['stock' => 'Insufficient eligible batch stock. Expired or recalled stock cannot be sold. Refresh inventory before continuing.']);
        }
        foreach ($batches as $batch) {
            if ($qty === 0) {
                break;
            }
            $take = min($qty, $batch->quantityRemaining);
            DB::table('inventory_batches')->where('id', $batch->id)->decrement('quantityRemaining', $take);
            DB::table('inventory_allocations')->insert(['batchId' => $batch->id, 'productId' => $id, 'referenceType' => $type,
                'referenceId' => $reference, 'quantity' => $take, 'unitCost' => $batch->unitCost, 'created_at' => now()]);
            $qty -= $take;
        }
    }

    public static function returnStock(int $id, int $qty, string $reference, bool $restock = true, bool $reservationRelease = false): void
    {
        $allocations = DB::table('inventory_allocations')->where('productId', $id)->where('referenceId', $reference)
            ->whereIn('referenceType', ['checkout', 'payment_reservation', 'exchange_replacement'])->orderBy('id')->lockForUpdate()->get();
        if ($allocations->isEmpty()) {
            // Sales made before migration have no recoverable original batch allocation.
            if ($restock) {
                $product = DB::table('products')->where('id', $id)->first();
                if (! $reservationRelease) {
                    throw ValidationException::withMessages(['stock' => 'The original batch is unknown. Refund without restocking, then reconcile the returned goods by batch.']);
                }
                self::receive($id, $qty, max(0, (float) $product->unitPrice), now()->toDateString(), null, null, 'legacy_return', expiry: $product->expiry);
            }

            return;
        }
        foreach ($allocations as $a) {
            $take = min($qty, $a->quantity - $a->returnedQuantity);
            if ($take <= 0) {
                continue;
            }
            if ($restock) {
                // Returned goods remain in their original cost/expiry/recall layer.
                // Physical receipt does not make an expired or recalled batch sellable.
                DB::table('inventory_batches')->where('id', $a->batchId)->increment('quantityRemaining', $take);
            }
            DB::table('inventory_allocations')->where('id', $a->id)->increment('returnedQuantity', $take);
            $qty -= $take;
            if ($qty === 0) {
                break;
            }
        }
        if ($qty > 0) {
            throw ValidationException::withMessages(['stock' => 'Returned quantity exceeds the original FIFO allocation.']);
        }
    }
}
