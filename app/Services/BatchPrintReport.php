<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BatchPrintReport
{
    public static function build(Collection $batches): array
    {
        $receiptIds = $batches->pluck('receiptId')->filter()->unique();
        $receipts = DB::table('receiving_records')->whereIn('id', $receiptIds)->get()->keyBy('id');
        $lines = DB::table('receiving_record_lines')->whereIn('receivingId', $receiptIds)->get();
        $orders = DB::table('purchase_order_lines')->whereIn('poId', $receipts->pluck('poId'))->get();
        $result = collect();
        foreach ($batches->groupBy(fn ($b) => $b->receiptId ?: 'stock-'.$b->id) as $entries) {
            $receipt = $receipts->get($entries->first()->receiptId);
            $inspection = collect(json_decode($receipt->inspection ?? '[]', true) ?: [])->keyBy('productId');
            $receiptLines = $lines->where('receivingId', $receipt->id ?? null);
            $grouped = $entries->groupBy(function ($b) use ($receiptLines) {
                $line = $receiptLines->firstWhere('productId', $b->productId);
                return $line->originalProductId ?? $b->productId;
            });
            foreach ($inspection as $productId => $snapshot) {
                if (!$grouped->has($productId) && ($snapshot['outstanding'] ?? 0) > 0) {
                    $order = $orders->where('poId', $receipt->poId)->firstWhere('productId', $productId);
                    $grouped->put($productId, collect([(object) [
                        'productId' => $productId, 'name' => $snapshot['name'],
                        'category' => $order->category ?? null, 'stockUnit' => $snapshot['unit'] ?? '',
                        'quantityReceived' => 0, 'unitCost' => $order->unitCost ?? 0,
                    ]]));
                }
            }
            foreach ($grouped as $productId => $items) {
                $snapshot = $inspection->get($productId);
                $line = $receiptLines->first(fn ($l) => ($l->originalProductId ?? $l->productId) == $productId);
                $accepted = (int) $items->sum('quantityReceived');
                $short = $snapshot !== null ? (int) ($snapshot['outstanding'] ?? 0)
                    : max(0, (int) ($line->poQty ?? $accepted) - $accepted);
                $excess = $snapshot !== null ? min($accepted, (int) ($snapshot['excess'] ?? 0))
                    : max(0, $accepted - (int) ($line->poQty ?? $accepted));
                $status = $short > 0 ? 'Incomplete items' : ($snapshot !== null || $line ? 'Complete items' : 'Items without order records');
                $first = true;
                foreach ($items->groupBy(fn ($b) => $b->productId.'|'.$b->unitCost.'|'.$b->stockUnit) as $lots) {
                    $item = $lots->first();
                    $received = (int) $lots->sum('quantityReceived');
                    // Count the outstanding balance once across split physical lots.
                    $missing = $first ? $short : 0;
                    $first = false;
                    $costCents = (int) round($item->unitCost * 100);
                    $result->push((object) [
                        'name' => $item->name, 'category' => $item->category,
                        'ordered' => $received + $missing, 'received' => $received,
                        'discrepancies' => $missing ? $missing.' outstanding (missing / damaged)' : ($excess ? $excess.' excess in this receipt' : 'None'),
                        'unit' => $item->stockUnit, 'unitCost' => $costCents / 100,
                        'subtotalCents' => ($received + $missing) * $costCents,
                        'deductionCents' => $missing * $costCents, 'status' => $status,
                    ]);
                }
            }
        }
        return ['tables' => $result->groupBy('status')->sortKeys(),
            'subtotal' => $result->sum('subtotalCents') / 100,
            'deductions' => $result->sum('deductionCents') / 100,
            'totalCost' => ($result->sum('subtotalCents') - $result->sum('deductionCents')) / 100];
    }
}
