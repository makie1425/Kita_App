<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class StandardizeLegacyBatches
{
    public static function run(): void
    {
        DB::transaction(function () {
            $rows = DB::table('inventory_batches as b')
                ->leftJoin('physical_inventory_lots as l', 'l.id', '=', 'b.physicalLotId')
                ->leftJoin('receiving_records as r', 'r.id', '=', 'b.receiptId')
                ->orderBy('b.id')->get(['b.*', 'l.stockReceipt', 'r.deliveryBatch', 'r.received_at']);
            $groups = $rows->groupBy(fn ($b) => $b->stockReceipt ? 'receipt:'.$b->stockReceipt
                : ($b->deliveryBatch ? 'delivery:'.$b->deliveryBatch
                : ($b->receiptId ? 'receipt:'.$b->receiptId : 'entry:'.$b->id)));
            foreach ($groups as $items) {
                $standard = $items->pluck('batchNumber')->filter(fn ($n) => preg_match('/^BATCH-\d{2}-\d{2}-\d{2}-\d{5,}$/', $n ?? ''))->unique();
                if ($standard->count() === 1 && $items->every(fn ($b) => $b->batchNumber === $standard->first())) continue;
                // Leave multiple established standard batches intact.
                if ($standard->count() > 1) continue;
                $first = $items->first();
                $number = $standard->first() ?: ReceivingNumber::next('BATCH', $first->received_at ?: $first->receivedDate ?: $first->created_at);
                foreach ($items as $b) {
                    if ($b->batchNumber === $number) continue;
                    DB::table('batch_number_history')->insertOrIgnore([
                        'inventoryBatchId' => $b->id, 'previousNumber' => $b->batchNumber,
                        'batchNumber' => $number, 'created_at' => now(),
                    ]);
                    $changes = ['batchNumber' => $number];
                    if ($b->lotNumber === $b->batchNumber) $changes['lotNumber'] = $number;
                    DB::table('inventory_batches')->where('id', $b->id)->update($changes);
                    if ($b->receiptId) {
                        $lines = DB::table('receiving_record_lines')->where('receivingId', $b->receiptId)
                            ->where('productId', $b->productId)->where('batchNumber', $b->batchNumber);
                        $lines->update(['batchNumber' => $number, 'internalBatch' => $number]);
                    }
                }
            }
        });
    }
}
