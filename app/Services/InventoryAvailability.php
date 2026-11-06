<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryAvailability
{
    public static function reservations(?array $productIds = null)
    {
        $transactions = DB::table('transactions');
        if (config('workspaces.enabled')) {
            $workspaceId = WorkspaceRead::id();
            $transactions->where('workspace_id', $workspaceId ?: -1);
        }

        return WorkspaceRead::table('inventory_allocations as a')->joinSub($transactions, 't', 't.uuid', '=', 'a.referenceId')
            ->when($productIds !== null, fn ($query) => $query->whereIn('a.productId', $productIds))
            ->where('a.referenceType', 'payment_reservation')->where('t.status', 'Pending Payment')
            ->groupBy('a.batchId')->select('a.batchId')->selectRaw('SUM(a.quantity - a.returnedQuantity) as reserved');
    }

    public static function products(array $products): array
    {
        if (config('workspaces.enabled')) {
            $visible = WorkspaceRead::table('products')->whereIn('id', array_column($products, 'id'))->pluck('id')->all();
            $products = array_values(array_filter($products, fn ($product) => in_array($product['id'], $visible)));
        }
        if (!$products) return [];
        $today = now()->toDateString();
        $balances = \App\Services\WorkspaceRead::table('inventory_batches as b')->leftJoinSub(self::reservations(array_column($products, 'id')), 'r', 'r.batchId', '=', 'b.id')
            ->whereIn('b.productId', array_column($products, 'id'))->groupBy('b.productId')
            ->select('b.productId')->selectRaw('SUM(b.quantityRemaining) as onHand, SUM(COALESCE(r.reserved, 0)) as reserved, SUM(CASE WHEN b.recalled = 0 AND (b.expiryDate IS NULL OR b.expiryDate > ?) THEN b.quantityRemaining ELSE 0 END) as sellable', [$today])
            ->selectRaw('SUM(CASE WHEN b.expiryDate <= ? THEN b.quantityRemaining + COALESCE(r.reserved, 0) ELSE 0 END) as expired, SUM(CASE WHEN b.recalled = 1 THEN b.quantityRemaining + COALESCE(r.reserved, 0) ELSE 0 END) as recalled', [$today])
            ->selectRaw('SUM(CASE WHEN b.recalled = 1 AND b.expiryDate <= ? THEN b.quantityRemaining + COALESCE(r.reserved, 0) ELSE 0 END) as expiredAndRecalled', [$today])
            ->selectRaw('SUM((b.quantityRemaining + COALESCE(r.reserved, 0)) * b.unitCost) as inventoryValue')
            ->get()->keyBy('productId');
        $categories = \App\Services\WorkspaceRead::table('categories')->whereIn('name', array_unique(array_column($products, 'category')))->where('status', 'Active')->whereNull('archivedAt')->pluck('name')->all();

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
            $active = ($product['status'] ?? 'Active') === 'Active' && empty($product['archivedAt']) && in_array($product['category'] ?? '', $categories, true);
            $product['needsReorder'] = $active && ! $product['stockMismatch'] && $product['sellableStock'] <= (int) ($product['minStock'] ?? 0);
            $product['reorderShortfall'] = max(0, (int) ($product['minStock'] ?? 0) - $product['sellableStock']);
            $product['stockCondition'] = ! $active ? 'Inactive' : ($product['stockMismatch'] ? 'Review balance' : ($product['stockOnHand'] === 0 ? 'Out of stock' : ($product['sellableStock'] === 0 ? 'Unavailable' : ($product['needsReorder'] ? 'Reorder level reached' : 'In stock'))));
            $product['inventoryValue'] = round($balance ? (float) $balance->inventoryValue : $product['stockOnHand'] * (float) ($product['unitPrice'] ?? 0), 2);
            $product['valuationBasis'] = $balance ? 'Recorded batch costs' : 'Estimated opening cost';

            return $product;
        }, $products);
    }

    public static function assertSellable(object $product, int $quantity, string $field = 'items'): array
    {
        $stock = self::products([(array) $product])[0] ?? null;
        if (! $stock) {
            throw ValidationException::withMessages([$field => 'Select an inventory item available to this account.']);
        }
        if ($quantity < 1 || $quantity > $stock['sellableStock']) {
            throw ValidationException::withMessages([$field => 'Only '.$stock['sellableStock'].' sellable units of '.$product->name.' are available. '.implode('; ', $stock['exclusionReasons']).'. Expired or recalled batches cannot be sold.']);
        }

        return $stock;
    }

    public static function batches($batches)
    {
        if (config('workspaces.enabled')) {
            $visible = WorkspaceRead::table('inventory_batches')->whereIn('id', $batches->pluck('id'))->pluck('id')->all();
            $batches = $batches->filter(fn ($batch) => in_array($batch->id, $visible))->values();
        }
        $reservations = self::reservations()->get()->keyBy('batchId');
        $categories = \App\Services\WorkspaceRead::table('categories')->where('status', 'Active')->whereNull('archivedAt')->pluck('name')->all();

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
            $batch->inventoryValue = round($batch->stockOnHand * (float) $batch->unitCost, 2);
            if ($batch->reservedQuantity) {
                $batch->exclusionReasons[] = 'Reserved for pending payment: '.$batch->reservedQuantity;
            }

            return $batch;
        });
    }
}
