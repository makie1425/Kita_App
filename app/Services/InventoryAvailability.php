<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryAvailability
{
    public static function reservations()
    {
        return DB::table('inventory_allocations as a')->join('transactions as t', 't.uuid', '=', 'a.referenceId')
            ->where('a.referenceType', 'payment_reservation')->where('t.status', 'Pending Payment')
            ->groupBy('a.batchId')->select('a.batchId')->selectRaw('SUM(a.quantity - a.returnedQuantity) as reserved');
    }

    public static function products(array $products): array
    {
        $today = now()->toDateString();
        $balances = DB::table('inventory_batches as b')->leftJoinSub(self::reservations(), 'r', 'r.batchId', '=', 'b.id')
            ->whereIn('b.productId', array_column($products, 'id'))->groupBy('b.productId')
            ->select('b.productId')->selectRaw('SUM(b.quantityRemaining) as onHand, SUM(COALESCE(r.reserved, 0)) as reserved, SUM(CASE WHEN b.recalled = 0 AND (b.expiryDate IS NULL OR b.expiryDate > ?) THEN b.quantityRemaining ELSE 0 END) as sellable', [$today])
            ->selectRaw('SUM(CASE WHEN b.expiryDate <= ? THEN b.quantityRemaining + COALESCE(r.reserved, 0) ELSE 0 END) as expired, SUM(CASE WHEN b.recalled = 1 THEN b.quantityRemaining + COALESCE(r.reserved, 0) ELSE 0 END) as recalled', [$today])
            ->selectRaw('SUM(CASE WHEN b.recalled = 1 AND b.expiryDate <= ? THEN b.quantityRemaining + COALESCE(r.reserved, 0) ELSE 0 END) as expiredAndRecalled', [$today])
            ->get()->keyBy('productId');
        $categories = DB::table('categories')->where('status', 'Active')->whereNull('archivedAt')->pluck('name')->all();

        return array_map(function ($product) use ($balances, $today, $categories) {
            $balance = $balances->get($product['id']);
            $sellable = $balance ? (int) $balance->sellable : ((! empty($product['expiry']) && $product['expiry'] <= $today) ? 0 : (int) $product['stock']);
            $product['reservedStock'] = $balance ? (int) $balance->reserved : 0;
            $product['stockOnHand'] = ($balance ? (int) $balance->onHand : (int) $product['stock']) + $product['reservedStock'];
            $product['expiredStock'] = $balance ? (int) $balance->expired : ((isset($product['expiry']) && $product['expiry'] <= $today) ? (int) $product['stock'] : 0);
            $product['recalledStock'] = $balance ? (int) $balance->recalled : 0;
            $product['expiredRecalledStock'] = $balance ? (int) $balance->expiredAndRecalled : 0;
            $product['stockMismatch'] = (int) $product['stock'] !== $product['stockOnHand'] - $product['reservedStock'];
            $reasons = [];
            if ($product['expiredStock']) {
                $reasons[] = 'Expired: '.$product['expiredStock'];
            }
            if ($product['recalledStock']) {
                $reasons[] = 'Recalled: '.$product['recalledStock'];
            }
            if ($product['reservedStock']) {
                $reasons[] = 'Reserved for pending payment: '.$product['reservedStock'].' (may also be expired or recalled)';
            }
            if ($product['expiredRecalledStock']) {
                $reasons[] = 'Both expired and recalled: '.$product['expiredRecalledStock'].' (counted once in unavailable total)';
            }
            if (($product['status'] ?? 'Active') !== 'Active' || ! empty($product['archivedAt']) || ! in_array($product['category'] ?? '', $categories, true)) {
                $sellable = 0;
                $reasons[] = 'Product or category is inactive, archived or quarantined';
            }
            if ($product['stockMismatch']) {
                $sellable = 0;
                $reasons[] = 'Batch balance mismatch - reconcile before selling';
            }
            $product['sellableStock'] = max(0, $sellable);
            $product['unavailableStock'] = max(0, $product['stockOnHand'] - $product['sellableStock']);
            $product['exclusionReasons'] = $reasons;

            return $product;
        }, $products);
    }

    public static function assertSellable(object $product, int $quantity, string $field = 'items'): array
    {
        $stock = self::products([(array) $product])[0];
        if ($quantity < 1 || $quantity > $stock['sellableStock']) {
            throw ValidationException::withMessages([$field => 'Only '.$stock['sellableStock'].' sellable units of '.$product->name.' are available. '.implode('; ', $stock['exclusionReasons']).'. Expired or recalled batches cannot be sold.']);
        }

        return $stock;
    }

    public static function batches($batches)
    {
        $reservations = self::reservations()->get()->keyBy('batchId');
        $categories = DB::table('categories')->where('status', 'Active')->whereNull('archivedAt')->pluck('name')->all();

        return $batches->map(function ($batch) use ($reservations, $categories) {
            $reasons = [];
            if ($batch->expiryDate && $batch->expiryDate <= now()->toDateString()) {
                $reasons[] = 'Expired';
            }
            if ($batch->recalled) {
                $reasons[] = 'Recalled';
            }
            if (($batch->productStatus ?? 'Active') !== 'Active' || ! empty($batch->productArchivedAt) || (isset($batch->category) && ! in_array($batch->category, $categories, true))) {
                $reasons[] = 'Product or category unavailable';
            }
            $batch->exclusionReasons = $reasons;
            $batch->eligibility = $reasons ? implode(' and ', $reasons) : ($batch->quantityRemaining > 0 ? 'Eligible' : 'Empty');
            $batch->sellableQuantity = $reasons ? 0 : max(0, (int) $batch->quantityRemaining);
            $batch->reservedQuantity = (int) ($reservations->get($batch->id)?->reserved ?? 0);
            $batch->stockOnHand = (int) $batch->quantityRemaining + $batch->reservedQuantity;
            if ($batch->reservedQuantity) {
                $batch->exclusionReasons[] = 'Reserved for pending payment: '.$batch->reservedQuantity;
            }

            return $batch;
        });
    }
}
