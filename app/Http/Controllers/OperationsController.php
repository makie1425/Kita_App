<?php

namespace App\Http\Controllers;

use App\Services\InventoryRules;
use App\Services\PurchaseWorkflow;
use App\Services\StockMovement;
use App\Services\WorkflowNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OperationsController extends Controller
{
    public function storeAdjustment(Request $request): JsonResponse
    {
        InventoryRules::authorize($request);
        $data = $request->validate([
            'id' => ['sometimes', 'required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/', 'unique:adjustments,id'],
            'productId' => ['required', 'integer', 'min:1'],
            'qtyChange' => ['required', 'integer', 'not_in:0', 'between:-2147483647,-1'],
            'reason' => ['required', 'string', 'max:80'], 'comment' => ['required', 'string', 'max:2000'], 'photo' => ['sometimes', 'boolean'],
        ]);
        $id = $data['id'] ?? 'ADJ-'.Str::ulid();
        DB::transaction(function () use ($request, $data, $id) {
            InventoryRules::lockActor($request);
            $product = InventoryRules::product($data['productId']);
            $this->stockAfter($product->stock, $data['qtyChange']);
            DB::table('adjustments')->insert([
                'id' => $id, 'productId' => $product->id, 'qtyChange' => $data['qtyChange'],
                'reason' => $data['reason'], 'comment' => $data['comment'], 'photo' => $data['photo'] ?? false,
                'status' => 'Pending Admin Approval', 'date' => now()->toDateString(), 'remarks' => 'Submitted by '.$request->user()->name,
                'requestedById' => $request->user()->id, 'requestedByRole' => $request->user()->role,
            ]);
            InventoryRules::audit($request->user()->name, 'Submitted adjustment', $id, null, 'Pending Admin Approval');
            WorkflowNotifications::send([...WorkflowNotifications::reviewers(), $request->user()], $request->user(),
                'Inventory approval requested', $request->user()->name.' submitted adjustment '.$id.' for approval.', 'adjustment', $id);
        }, 3);

        return response()->json(['message' => 'Inventory adjustment submitted.', 'id' => $id], 201);
    }

    public function updateAdjustment(Request $request, string $id): JsonResponse
    {
        InventoryRules::authorize($request, ['admin', 'superadmin', 'super_admin']);
        $data = $request->validate(['status' => ['required', 'in:Approved,Rejected']]);

        return DB::transaction(function () use ($request, $data, $id) {
            $adjustment = DB::table('adjustments')->where('id', $id)->lockForUpdate()->first();
            abort_unless($adjustment, 404, 'Adjustment not found.');
            abort_unless($adjustment->status === 'Pending Admin Approval', 409, 'This adjustment has already been processed.');
            if ($data['status'] === 'Approved') {
                if ($adjustment->qtyChange > 0) {
                    throw ValidationException::withMessages(['qtyChange' => 'Receive a purchase to add stock. Positive manual adjustments are no longer allowed.']);
                }
                $product = InventoryRules::product($adjustment->productId);
                $after = $this->stockAfter($product->stock, $adjustment->qtyChange);
                DB::table('products')->where('id', $product->id)->update(['stock' => $after]);
                StockMovement::record($product->id, $product->stock, $after, 'adjustment', $id, $request->user());
            }
            DB::table('adjustments')->where('id', $id)->update(['status' => $data['status']]);
            InventoryRules::audit($request->user()->name, $data['status'].' adjustment', $id, $adjustment->status, $data['status']);
            WorkflowNotifications::send([WorkflowNotifications::owner($adjustment->requestedById, $adjustment->requestedByRole), $request->user()], $request->user(),
                'Inventory adjustment '.$data['status'], 'Adjustment '.$id.' was '.strtolower($data['status']).' by '.$request->user()->name.'.', 'adjustment', $id);

            return response()->json(['message' => 'Inventory adjustment updated.', 'id' => $id]);
        }, 3);
    }

    public function storeRequest(Request $request): JsonResponse
    {
        return app(PurchaseWorkflow::class)->create($request);
    }

    public function updateRequest(Request $request, string $id): JsonResponse
    {
        return app(PurchaseWorkflow::class)->review($request, $id);
    }

    public function addPurchaseOrderItems(Request $request, string $id): JsonResponse
    {
        InventoryRules::authorize($request);
        $data = $request->validate($this->lineRules());

        return DB::transaction(function () use ($request, $data, $id) {
            $order = DB::table('purchase_orders')->where('id', $id)->lockForUpdate()->first();
            abort_unless($order, 404, 'Purchase order not found.');
            abort_unless(in_array($order->status, ['Draft', 'Approved', 'Ordered']) && (float) $order->paidValue === 0.0
                && (float) $order->deliveredValue === 0.0 && (float) $order->invoicedValue === 0.0, 409, 'Items cannot be added after delivery, invoicing, payment, or closure.');
            if (DB::table('purchase_order_lines')->where('poId', $id)->where('deliveredQty', '>', 0)->exists()) {
                abort(409, 'This order has already received items.');
            }
            $lines = $this->normalizeLines($data['lines'], $order->supplierId);
            foreach ($lines as $line) {
                $existing = DB::table('purchase_order_lines')->where('poId', $id)->where('productId', $line['productId'])->orderBy('id')->lockForUpdate()->get();
                $qty = (int) $existing->sum('orderedQty') + $line['qty'];
                $unitCost = $existing->isEmpty() ? $line['unitCost'] : (float) $existing->first()->unitCost;
                if (isset($line['explicitCost']) && abs($line['explicitCost'] - $unitCost) > 0.001) {
                    throw ValidationException::withMessages(['lines' => 'Use the existing unit cost when adding more of the same item.']);
                }
                $values = ['name' => $line['name'], 'orderedQty' => $qty, 'unitCost' => $unitCost, 'lineTotal' => InventoryRules::lineTotal($qty, $unitCost)];
                if ($existing->isEmpty()) {
                    DB::table('purchase_order_lines')->insert($values + ['poId' => $id, 'productId' => $line['productId'], 'deliveredQty' => 0]);
                } else {
                    DB::table('purchase_order_lines')->where('id', $existing->first()->id)->update($values);
                    DB::table('purchase_order_lines')->whereIn('id', $existing->skip(1)->pluck('id'))->delete();
                }
            }
            $total = round((float) DB::table('purchase_order_lines')->where('poId', $id)->sum('lineTotal'), 2);
            if ($total > 9999999999.99) {
                throw ValidationException::withMessages(['lines' => 'The order amount is too large.']);
            }
            DB::table('purchase_orders')->where('id', $id)->update(['orderedValue' => $total, 'outstandingValue' => $total]);
            InventoryRules::audit($request->user()->name, 'Added PO items', $id, (string) $order->orderedValue, (string) $total);

            return response()->json(['message' => 'Purchase order items saved.', 'orderedValue' => $total]);
        }, 3);
    }

    private function lineRules(): array
    {
        return ['lines' => ['required', 'array', 'min:1', 'max:500'], 'lines.*.productId' => ['required', 'integer', 'min:1'],
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:1000000'], 'lines.*.unitCost' => ['sometimes', ...InventoryRules::moneyRules()],
            'lines.*.supplierId' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('status', 'Active')]];
    }

    private function normalizeLines(array $input, ?int $supplierId = null): array
    {
        $lines = [];
        foreach (collect($input)->sortBy('productId') as $line) {
            $product = InventoryRules::product((int) $line['productId'], 'lines');
            $supplier = $supplierId ?? ($line['supplierId'] ?? $product->supplierId);
            $supplier = $supplier ? (int) $supplier : null;
            if ($supplier && ! DB::table('suppliers')->where('id', $supplier)->where('status', 'Active')->whereNull('archivedAt')->exists()) {
                throw ValidationException::withMessages(['lines' => 'Select an active supplier.']);
            }
            if ($supplierId && (int) $product->supplierId !== $supplierId && ! DB::table('supplier_products')->where('supplierId', $supplierId)->where('productId', $product->id)->exists()) {
                throw ValidationException::withMessages(['lines' => 'This item is not supplied by the purchase order supplier.']);
            }
            $cost = isset($line['unitCost']) ? (float) $line['unitCost'] : InventoryRules::unitCost($product);
            if (isset($lines[$product->id]) && ($cost !== $lines[$product->id]['unitCost'] || $supplier !== $lines[$product->id]['supplierId'])) {
                throw ValidationException::withMessages(['lines' => 'Duplicate items must use the same supplier and unit cost.']);
            }
            $qty = ($lines[$product->id]['qty'] ?? 0) + (int) $line['qty'];
            if ($qty < 1 || $qty > 1000000) {
                throw ValidationException::withMessages(['lines' => 'Quantity must be greater than zero and within the allowed range.']);
            }
            $lines[$product->id] = ['productId' => $product->id, 'name' => InventoryRules::productLabel($product), 'qty' => $qty, 'supplierId' => $supplier,
                'unitCost' => $cost, 'lineTotal' => InventoryRules::lineTotal($qty, $cost)];
            if (isset($line['unitCost'])) {
                $lines[$product->id]['explicitCost'] = $cost;
            }
        }
        if (! $lines) {
            throw ValidationException::withMessages(['lines' => 'Add at least one valid item.']);
        }

        return array_values($lines);
    }

    private function stockAfter(int $stock, int $change): int
    {
        $after = $stock + $change;
        if ($after < 0 || $after > 2147483647) {
            throw ValidationException::withMessages(['qtyChange' => 'Insufficient stock or resulting quantity is outside the allowed range.']);
        }

        return $after;
    }
}
