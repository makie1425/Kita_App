<?php

namespace App\Http\Controllers;

use App\Services\FifoInventory;
use App\Services\InventoryRules;
use App\Services\PurchaseWorkflow;
use App\Services\StockMovement;
use App\Services\WorkflowNotifications;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchasingController extends Controller
{
    public function index(Request $request)
    {
        InventoryRules::authorize($request);
        $requestLines = DB::table('item_request_lines')->orderBy('id')->get()->groupBy('requestId');
        $requests = DB::table('item_requests')->orderByDesc('dateRequested')->orderByDesc('id')->get()->map(function ($record) use ($requestLines) {
            $record->lines = $requestLines->get($record->id, collect());
            unset($record->submissionKey, $record->submissionHash);

            return $record;
        });
        $orders = DB::table('purchase_orders')->orderByDesc('created')->orderByDesc('id')->get();
        $lines = DB::table('purchase_order_lines')->orderBy('id')->get()->groupBy('poId');
        $receipts = DB::table('receiving_records')->orderByDesc('received_at')->orderByDesc('id')->get();
        $receiptLines = DB::table('receiving_record_lines')->orderBy('id')->get()->groupBy('receivingId');
        foreach ($receipts as $receipt) {
            $receipt->lines = $receiptLines->get($receipt->id, collect());
            unset($receipt->payloadHash, $receipt->idempotencyKey);
        }
        foreach ($orders as $order) {
            $order->lines = $lines->get($order->id, collect());
            $order->request = $requests->firstWhere('id', $order->itemRequestId);
            $order->receipts = $receipts->where('poId', $order->id)->values();
        }

        return response()->json(['requests' => $requests, 'orders' => $orders])->header('Cache-Control', 'no-store');
    }

    public function receive(Request $request, string $id)
    {
        InventoryRules::authorize($request, ['manager', 'superadmin', 'super_admin']);
        $data = $request->validate([
            'idempotencyKey' => ['required', 'uuid'],
            'version' => ['required', 'integer', 'min:0'],
            'receivedDate' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.productId' => ['required', 'integer', 'distinct'],
            'lines.*.qty' => ['required', 'integer', 'between:1,1000000'],
            'lines.*.unitCost' => ['sometimes', ...InventoryRules::moneyRules()],
            'lines.*.batchNumber' => ['nullable', 'string', 'max:80'],
            'lines.*.expiryDate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:receivedDate'],
        ]);
        // Generated from the PO and receiving version; ignore client-supplied references.
        $data['deliveryReference'] = $id.'-R'.str_pad((string) ($data['version'] + 1), 3, '0', STR_PAD_LEFT);
        $data['lines'] = collect($data['lines'])->sortBy('productId')->values()->all();
        $hash = hash('sha256', json_encode([$id, $data['deliveryReference'], $data['receivedDate'], $data['notes'] ?? '', $data['lines']]));

        return DB::transaction(function () use ($request, $id, $data, $hash) {
            InventoryRules::lockActor($request);
            $order = DB::table('purchase_orders')->where('id', $id)->lockForUpdate()->first();
            abort_unless($order, 404, 'Purchase order not found.');
            $existing = DB::table('receiving_records')->where('idempotencyKey', $data['idempotencyKey'])->first();
            if ($existing) {
                abort_unless($existing->poId === $id && $existing->payloadHash === $hash, 409, 'This receiving key was already used for another submission.');

                return response()->json(['message' => 'Delivery was already recorded; inventory was not added again.', 'id' => $existing->id, 'poId' => $id]);
            }
            $purchaseRequest = DB::table('item_requests')->where('id', $order->itemRequestId)->first();
            abort_unless($purchaseRequest && in_array($purchaseRequest->status, [PurchaseWorkflow::WAITING, 'Partially Received', 'Approved']), 409, 'The linked purchase request is not approved.');
            // The previous approval endpoint created Draft orders from approved requests.
            $approvedLegacyDraft = $order->status === 'Draft' && $purchaseRequest->status === 'Approved';
            abort_unless($approvedLegacyDraft || in_array($order->status, [PurchaseWorkflow::WAITING, 'Partially Received', 'Approved', 'Ordered']), 409, 'Only an approved PO awaiting delivery can receive stock.');
            abort_unless((int) $order->receivingVersion === $data['version'], 409, 'Another delivery was recorded. Refresh this PO before receiving again.');
            // Imported receipts may already use this format. Allocate the next free suffix.
            $number = $data['version'] + 1;
            while (DB::table('receiving_records')->where('poId', $id)->where('deliveryReference', $data['deliveryReference'])->exists()) {
                $data['deliveryReference'] = $id.'-R'.str_pad((string) ++$number, 3, '0', STR_PAD_LEFT);
            }
            if ($data['receivedDate'] < substr($purchaseRequest->approved_at ?? $order->created, 0, 10)) {
                throw ValidationException::withMessages(['receivedDate' => 'Receiving date cannot precede approval.']);
            }
            $orderLines = DB::table('purchase_order_lines')->where('poId', $id)->orderBy('productId')->lockForUpdate()->get();
            abort_if($orderLines->pluck('productId')->duplicates()->isNotEmpty(), 409, 'This legacy PO has duplicate item lines and requires reconciliation before receiving.');
            $receiptId = 'RCV-'.Str::ulid();
            DB::table('receiving_records')->insert(['id' => $receiptId, 'poId' => $id]);
            $receivedCents = 0;
            foreach ($data['lines'] as $line) {
                $ordered = $orderLines->firstWhere('productId', $line['productId']);
                if (! $ordered) {
                    throw ValidationException::withMessages(['lines' => 'A delivered item does not belong to this PO.']);
                }
                $product = InventoryRules::product($line['productId'], 'lines');
                if ($ordered->unit && $ordered->unit !== ($product->stockUnit ?: $product->unit)) {
                    throw ValidationException::withMessages(['lines' => 'The inventory unit has changed since approval. Reconcile the unit before receiving.']);
                }
                $after = (int) $product->stock + $line['qty'];
                $received = (int) $ordered->deliveredQty + $line['qty'];
                if ($after > 2147483647 || $received > 2147483647) {
                    throw ValidationException::withMessages(['lines' => 'Resulting stock exceeds the supported quantity.']);
                }
                if ($received > $ordered->orderedQty && ! trim($data['notes'] ?? '')) {
                    throw ValidationException::withMessages(['notes' => 'Explain the excess delivery before posting it.']);
                }
                $cost = (float) ($line['unitCost'] ?? $ordered->unitCost);
                $receivedCents += (int) round(InventoryRules::lineTotal($line['qty'], $cost) * 100);
                FifoInventory::opening($product->id, (int) $product->stock);
                FifoInventory::receive($product->id, $line['qty'], $cost, $data['receivedDate'], $receiptId, $order->supplierId, 'purchase_receiving', $line['batchNumber'] ?? null, $line['expiryDate'] ?? null);
                DB::table('products')->where('id', $product->id)->update(['stock' => $after]);
                StockMovement::record($product->id, $product->stock, $after, 'purchase_receiving', $receiptId, $request->user());
                DB::table('purchase_order_lines')->where('id', $ordered->id)->update(['deliveredQty' => $received]);
                $ordered->deliveredQty = $received;
                DB::table('receiving_record_lines')->insert([
                    'receivingId' => $receiptId, 'productId' => $product->id, 'poQty' => $ordered->orderedQty,
                    'deliveredQty' => $line['qty'], 'name' => $ordered->name, 'category' => $ordered->category ?? $product->category,
                    'unit' => $ordered->unit ?? ($product->stockUnit ?: $product->unit), 'conditionText' => 'Accepted',
                    'unitCost' => $cost, 'batchNumber' => $line['batchNumber'] ?? null, 'expiryDate' => $line['expiryDate'] ?? null,
                ]);
            }
            $matches = $orderLines->every(fn ($line) => (int) $line->deliveredQty === (int) $line->orderedQty);
            $short = $orderLines->contains(fn ($line) => $line->deliveredQty < $line->orderedQty);
            // Snapshot every PO line, including items absent from this delivery.
            // Later deliveries must not rewrite this inspection's missing quantities.
            $inspection = $orderLines->map(fn ($line) => [
                'productId' => $line->productId, 'name' => $line->name, 'unit' => $line->unit,
                'ordered' => (int) $line->orderedQty, 'received' => (int) $line->deliveredQty,
                'missing' => max(0, (int) $line->orderedQty - (int) $line->deliveredQty),
                'excess' => max(0, (int) $line->deliveredQty - (int) $line->orderedQty),
            ])->values()->all();
            $shortageSummary = collect($inspection)->filter(fn ($line) => $line['missing'] > 0)
                ->map(fn ($line) => $line['name'].': '.$line['missing'].' '.($line['unit'] ?? 'units').' missing')->implode('; ');
            $status = $matches ? 'Fully Received' : ($short ? 'Partially Received' : 'Received with Discrepancy');
            $deliveredValue = ((int) round((float) $order->deliveredValue * 100) + $receivedCents) / 100;
            if ($deliveredValue > 9999999999.99) {
                throw ValidationException::withMessages(['lines' => 'Received value exceeds the supported amount.']);
            }
            DB::table('receiving_records')->where('id', $receiptId)->update([
                'id' => $receiptId, 'poId' => $id, 'date' => $data['receivedDate'], 'received_at' => now(),
                'deliveryStatus' => $status, 'discrepancy' => ! $matches, 'discrepancyType' => $short ? 'Missing items' : ($matches ? null : 'Quantity difference'),
                'inspection' => json_encode($inspection),
                'outcome' => $data['notes'] ?? '', 'adminApproval' => 'Posted by Manager', 'barcodeAssignment' => 'Existing inventory barcode',
                'idempotencyKey' => $data['idempotencyKey'], 'deliveryReference' => $data['deliveryReference'], 'payloadHash' => $hash,
                'receivedById' => $request->user()->id, 'receivedByRole' => $request->user()->role, 'receivedBy' => $request->user()->name,
                'supplierName' => $order->supplierName ?? DB::table('suppliers')->where('id', $order->supplierId)->value('name'),
            ]);
            $receiptIds = DB::table('receiving_records')->where('poId', $id)->pluck('id')->all();
            DB::table('purchase_orders')->where('id', $id)->update([
                'status' => $status, 'receivingVersion' => $order->receivingVersion + 1, 'received_at' => now(),
                'receivingRecordIds' => json_encode($receiptIds), 'deliveredValue' => $deliveredValue,
                'outstandingValue' => max(0, (float) $order->orderedValue - $deliveredValue),
            ]);
            DB::table('item_requests')->where('id', $order->itemRequestId)->update(['status' => $status]);
            InventoryRules::audit($request->user()->name, 'Received purchase delivery', $id, $order->status, json_encode(['receiptId' => $receiptId, 'status' => $status, 'lines' => $data['lines']]));
            $approver = WorkflowNotifications::owner($purchaseRequest->approvedById ?? $order->createdById, $purchaseRequest->approvedByRole ?? $order->createdByRole);
            WorkflowNotifications::send([
                ...($approver ? [$approver] : WorkflowNotifications::reviewers()),
                WorkflowNotifications::owner($purchaseRequest->requestedById, $purchaseRequest->requestedByRole), $request->user(),
            ], $request->user(), 'Stock received', 'PO '.$id.': '.$status.'. Delivery '.$data['deliveryReference'].' recorded by '.$request->user()->name.'.'.($short ? ' Shortage report: '.$shortageSummary.'. Actual received items have been added to inventory.' : ''), 'receiving', $id);

            return response()->json(['message' => 'Delivery recorded and actual quantities added to inventory.'.($short ? ' A shortage report has been saved.' : ''), 'id' => $receiptId, 'poId' => $id, 'status' => $status, 'inspection' => $inspection], 201);
        }, 3);
    }

    public function supplier(Request $request)
    {
        InventoryRules::authorize($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'], 'contact' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'], 'email' => ['nullable', 'email', 'max:160'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        return DB::transaction(function () use ($request, $data) {
            InventoryRules::lockActor($request);
            $sequence = DB::table('inventory_sequences')->where('name', 'suppliers')->lockForUpdate()->first();
            $data['name'] = trim($data['name']);
            if (! $data['name'] || DB::table('suppliers')->whereRaw('LOWER(TRIM(name)) = ?', [Str::lower($data['name'])])->exists()) {
                throw ValidationException::withMessages(['name' => 'Enter a unique supplier name.']);
            }
            $id = max((int) $sequence->lastValue, (int) DB::table('suppliers')->max('id')) + 1;
            DB::table('inventory_sequences')->where('name', 'suppliers')->update(['lastValue' => $id]);
            DB::table('suppliers')->insert($data + ['id' => $id, 'status' => 'Active', 'callbackLog' => '[]']);
            InventoryRules::audit($request->user()->name, 'Created supplier', (string) $id, null, json_encode($data));

            return response()->json(['message' => 'Supplier created.', 'supplier' => $data + ['id' => $id, 'status' => 'Active']], 201);
        }, 3);
    }

    public function report(Request $request, string $id)
    {
        InventoryRules::authorize($request);
        $order = DB::table('purchase_orders')->where('id', $id)->first();
        abort_unless($order, 404);
        $purchase = DB::table('item_requests')->where('id', $order->itemRequestId)->first();
        $supplier = DB::table('suppliers')->where('id', $order->supplierId)->first();
        $lines = DB::table('purchase_order_lines')->where('poId', $id)->orderBy('id')->get();
        $receipts = DB::table('receiving_records')->where('poId', $id)->orderBy('received_at')->orderBy('id')->get();
        $receiptLines = DB::table('receiving_record_lines')->whereIn('receivingId', $receipts->pluck('id'))->get()->groupBy('receivingId');

        return response()->view('purchase-report', compact('order', 'purchase', 'supplier', 'lines', 'receipts', 'receiptLines'))->header('Cache-Control', 'private, no-store');
    }
}
