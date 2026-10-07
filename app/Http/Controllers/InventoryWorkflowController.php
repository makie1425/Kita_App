<?php

namespace App\Http\Controllers;

use App\Services\FifoInventory;
use App\Services\InventoryRules;
use App\Services\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InventoryWorkflowController extends Controller
{
    public function index(Request $r)
    {
        InventoryRules::authorize($r);
        $counts = DB::table('inventory_counts')->orderByDesc('id')->get();
        foreach ($counts as $count) {
            $count->lines = DB::table('inventory_count_lines as l')->join('products as p', 'p.id', '=', 'l.productId')->where('countId', $count->id)->get(['l.*', 'p.name', 'p.stockUnit']);
            if ($count->mode === 'Blind' && $count->status === 'Open') {
                foreach ($count->lines as $line) {
                    unset($line->expected);
                }
            }
        }

        return response()->json([
            'batches' => DB::table('inventory_batches as b')->join('products as p', 'p.id', '=', 'b.productId')->where('quantityRemaining', '>', 0)->orderByDesc('b.id')->get(['b.*', 'p.name', 'p.category', 'p.stockUnit']),
            'events' => DB::table('inventory_events as e')->join('products as p', 'p.id', '=', 'e.productId')->join('inventory_batches as b', 'b.id', '=', 'e.batchId')->orderByDesc('e.id')->get(['e.*', 'p.name', 'b.batchNumber']),
            'counts' => $counts, 'today' => now()->toDateString(),
        ])->header('Cache-Control', 'no-store');
    }

    public function event(Request $r)
    {
        InventoryRules::authorize($r);
        $d = $r->validate(['requestKey' => ['required', 'uuid'], 'batchId' => ['required', 'integer', 'exists:inventory_batches,id'],
            'type' => ['required', Rule::in(['Write-off', 'Disposal', 'Transfer', 'Recall'])], 'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'destination' => ['required_if:type,Transfer', 'nullable', 'string', 'max:255'], 'reason' => ['required', 'string', 'max:2000']]);

        return DB::transaction(function () use ($r, $d) {
            InventoryRules::lockActor($r);
            $info = DB::table('inventory_batches')->where('id', $d['batchId'])->first();
            $p = InventoryRules::product($info->productId);
            $old = DB::table('inventory_events')->where('requestKey', $d['requestKey'])->first();
            if ($old) {
                foreach (['batchId', 'type', 'quantity', 'reason'] as $field) {
                    abort_unless((string) $old->$field === (string) $d[$field], 409, 'This submission key was already used.');
                }
                abort_unless(($old->destination ?? '') === ($d['destination'] ?? ''), 409, 'This submission key was already used.');

                return response()->json(['message' => 'Already recorded.']);
            }
            $b = DB::table('inventory_batches')->where('id', $d['batchId'])->lockForUpdate()->first();
            abort_if($d['quantity'] > $b->quantityRemaining || $d['quantity'] > $p->stock, 422, 'Quantity exceeds the available batch stock.');
            if ($d['type'] === 'Recall') {
                abort_unless((int) $d['quantity'] === (int) $b->quantityRemaining, 422, 'Recall the entire remaining batch quantity.');
                DB::table('inventory_batches')->where('id', $b->id)->update(['recalled' => true]);
            }
            $id = DB::table('inventory_events')->insertGetId($d + ['productId' => $p->id, 'actor' => $r->user()->name, 'created_at' => now()]);
            DB::table('inventory_batches')->where('id', $b->id)->decrement('quantityRemaining', $d['quantity']);
            DB::table('products')->where('id', $p->id)->decrement('stock', $d['quantity']);
            DB::table('inventory_allocations')->insert(['batchId' => $b->id, 'productId' => $p->id, 'referenceType' => strtolower($d['type']), 'referenceId' => 'INV-'.$id, 'quantity' => $d['quantity'], 'unitCost' => $b->unitCost, 'created_at' => now()]);
            abort_unless((int) DB::table('inventory_batches')->where('productId', $p->id)->sum('quantityRemaining') === $p->stock - $d['quantity'], 422, 'Batch balances do not match stock.');
            DB::table('stock_movements')->insert(['productId' => $p->id, 'quantityChange' => -$d['quantity'], 'quantityBefore' => $p->stock, 'quantityAfter' => $p->stock - $d['quantity'], 'referenceType' => strtolower($d['type']), 'referenceId' => 'INV-'.$id, 'userId' => $r->user()->id, 'userRole' => $r->user()->role, 'created_at' => now()]);
            InventoryRules::audit($r->user()->name, 'Inventory '.$d['type'], 'INV-'.$id, null, json_encode($d));

            return response()->json(['message' => 'Inventory action recorded.'], 201);
        }, 3);
    }

    public function archive(Request $r)
    {
        InventoryRules::authorize($r);
        $d = $r->validate(['kind' => ['required', Rule::in(['products', 'categories'])], 'ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['required', 'string', 'max:100']]);

        return DB::transaction(function () use ($r, $d) {
            InventoryRules::lockActor($r);
            $key = $d['kind'] === 'products' ? 'id' : 'name';
            $records = DB::table($d['kind'])->whereIn($key, $d['ids'])->orderBy($key)->lockForUpdate()->get();
            abort_unless($records->count() === count(array_unique($d['ids'])), 422, 'Some selected records no longer exist.');
            DB::table($d['kind'])->whereIn($key, $d['ids'])->update(['status' => 'Inactive', 'archivedAt' => now()->toDateString(), 'archivedBy' => $r->user()->name]);
            InventoryRules::audit($r->user()->name, 'Archived inventory records', $d['kind'], null, json_encode($d['ids']));

            return response()->json(['message' => 'Selected records archived.']);
        });
    }

    public function start(Request $r)
    {
        InventoryRules::authorize($r);
        $d = $r->validate(['mode' => ['required', Rule::in(['Cycle', 'Blind', 'Full'])], 'category' => ['nullable', 'string', 'exists:categories,name']]);

        return DB::transaction(function () use ($r, $d) {
            InventoryRules::lockActor($r);
            $query = DB::table('products')->where('status', 'Active')->whereNull('archivedAt');
            if ($d['mode'] !== 'Full' && ! empty($d['category'])) {
                $query->where('category', $d['category']);
            }
            $products = $query->orderBy('id')->lockForUpdate()->get();
            abort_if($products->isEmpty(), 422, 'No active products to count.');
            abort_if(DB::table('inventory_count_lines as l')->join('inventory_counts as c', 'c.id', '=', 'l.countId')->where('c.status', 'Open')->whereIn('l.productId', $products->pluck('id'))->exists(), 409, 'An open count already includes these products. Complete or cancel it first.');
            $id = DB::table('inventory_counts')->insertGetId(['mode' => $d['mode'], 'category' => $d['mode'] === 'Full' ? null : ($d['category'] ?? null), 'actor' => $r->user()->name, 'created_at' => now(), 'status' => 'Open']);
            foreach ($products as $p) {
                DB::table('inventory_count_lines')->insert(['countId' => $id, 'productId' => $p->id, 'expected' => $p->stock, 'movementId' => DB::table('stock_movements')->where('productId', $p->id)->max('id') ?? 0]);
            }
            InventoryRules::audit($r->user()->name, 'Started stock count', (string) $id, null, $d['mode']);

            return response()->json(['message' => 'Count started. Pause physical movements while counting.', 'id' => $id], 201);
        }, 3);
    }

    public function close(Request $r, int $id)
    {
        InventoryRules::authorize($r);
        $d = $r->validate(['action' => ['required', Rule::in(['Complete', 'Cancel'])], 'reason' => ['required', 'string', 'max:2000'],
            'lines' => ['required_if:action,Complete', 'array'], 'lines.*.productId' => ['required', 'integer', 'distinct'], 'lines.*.counted' => ['required', 'integer', 'min:0', 'max:1000000']]);

        return DB::transaction(function () use ($r, $d, $id) {
            InventoryRules::lockActor($r);
            $c = DB::table('inventory_counts')->where('id', $id)->lockForUpdate()->first();
            abort_unless($c, 404);
            abort_unless($c->status === 'Open', 409, 'This count is already closed.');
            if ($d['action'] === 'Complete') {
                $lines = DB::table('inventory_count_lines')->where('countId', $id)->orderBy('productId')->get();
                $entered = collect($d['lines'])->keyBy('productId');
                abort_unless($entered->count() === $lines->count(), 422, 'Enter a physical count for every product.');
                foreach ($lines as $line) {
                    abort_unless($entered->has($line->productId), 422, 'Missing count item.');
                    $p = InventoryRules::product($line->productId);
                    $movement = (int) (DB::table('stock_movements')->where('productId', $p->id)->max('id') ?? 0);
                    abort_if($movement !== (int) $line->movementId || (int) $p->stock !== (int) $line->expected, 409, 'Stock moved during this count. Cancel and start a fresh count before reconciling.');
                    $actual = $entered[$p->id]['counted'];
                    FifoInventory::opening($p->id, (int) $p->stock);
                    if ($actual > $p->stock) {
                        FifoInventory::receive($p->id, $actual - $p->stock, max(0, (float) $p->unitPrice), now()->toDateString(), 'COUNT-'.$id, null, 'count_surplus');
                    }
                    DB::table('products')->where('id', $p->id)->update(['stock' => $actual]);
                    StockMovement::record($p->id, $p->stock, $actual, 'reconciliation', 'COUNT-'.$id, $r->user());
                    DB::table('inventory_count_lines')->where('id', $line->id)->update(['counted' => $actual]);
                }
            }
            DB::table('inventory_counts')->where('id',$id)->update(['status' => $d['action'] === 'Complete' ? 'Completed' : 'Cancelled', 'closed_at' => now(), 'reason' => $d['reason']]);
            InventoryRules::audit($r->user()->name,'Closed stock count',(string) $id,'Open',json_encode($d));

            return response()->json(['message' => 'Stock count '.$d['action'].'.']);
        }, 3);
    }
}
