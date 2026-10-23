<?php

namespace App\Http\Controllers;

use App\Services\FifoInventory;
use App\Services\InventoryRules;
use App\Services\PurchaseWorkflow;
use App\Services\ReceivingCarryover;
use App\Services\ReceivingRemarks;
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
        $contacts = DB::table('supplier_contacts')->orderByDesc('id')->get()->groupBy('receiptId');
        foreach ($receipts as $receipt) {
            $receipt->remarks = ReceivingRemarks::forReceipt($receipt);
            $receipt->contacts = $contacts->get($receipt->id, collect());
            $receipt->lines = $receiptLines->get($receipt->id, collect());
            unset($receipt->payloadHash, $receipt->idempotencyKey);
        }
        foreach ($orders as $order) {
            $order->lines = $lines->get($order->id, collect());
            $order->request = $requests->firstWhere('id', $order->itemRequestId);
            $order->receipts = $receipts->where('poId', $order->id)->values();
            $order->fulfillmentStatus = $order->status === 'Cancelled' ? 'Cancelled' : ($order->receipts->isEmpty() ? 'Awaiting delivery' : ($order->lines->contains(fn ($l) => ReceivingCarryover::remaining($l) > 0) ? 'Partially Received' : 'Fully Received'));
            $order->remarks = $order->receipts->isEmpty() ? 'Awaiting delivery' : $order->receipts->pluck('remarks')->implode(' | ');
        }

        return response()->json(['requests' => $requests, 'orders' => $orders, 'carryovers'=>DB::table('receiving_carryovers')->get(), 'resolutions'=>DB::table('receiving_carryover_resolutions')->get(), 'inspections'=>DB::table('receiving_inspections')->orderBy('id')->get()])->header('Cache-Control', 'no-store');
    }

    public function carryover(Request $request, string $id) { return ReceivingCarryover::allocate($request, $id); }
    public function releaseCarryover(Request $request, int $id) { return ReceivingCarryover::release($request, $id); }

    public function receive(Request $request, string $id)
    {
        InventoryRules::authorize($request, ['manager', 'superadmin', 'super_admin']);
        $data = $request->validate([
            'idempotencyKey' => ['required', 'uuid'],
            'version' => ['required', 'integer', 'min:0'],
            'receivedDate' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'paymentDueDate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:receivedDate'],
            'supplierDocument' => ['nullable', 'string', 'max:100'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.productId' => ['required', 'integer', 'distinct'],
            'lines.*.substituteProductId' => ['nullable', 'integer'],
            'lines.*.substitutionReason' => ['nullable', 'in:missing,damaged'],
            'lines.*.qty' => ['required', 'integer', 'between:0,1000000'],
            'lines.*.damagedQty' => ['sometimes','integer','between:0,1000000'],
            'lines.*.damageReason' => ['nullable','string','max:500'],
            'lines.*.unitCost' => ['sometimes', ...InventoryRules::moneyRules()],
            'lines.*.sellingPrice' => ['sometimes', ...InventoryRules::moneyRules()],
            'lines.*.batchNumber' => ['nullable', 'string', 'max:80'],
            'lines.*.expiryDate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:receivedDate'],
            'lines.*.lotNumber' => ['nullable', 'string', 'max:40'],
            'lines.*.manufacturedDate' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:receivedDate'],
        ]);
        if (!empty($data['paymentDueDate']) || !empty($data['supplierDocument'])) \App\Services\SupplierAccounts::requireReady();
        // Generated from the PO and receiving version; ignore client-supplied references.
        $data['deliveryReference'] = $id.'-R'.str_pad((string) ($data['version'] + 1), 3, '0', STR_PAD_LEFT);
        $data['lines'] = collect($data['lines'])->sortBy('productId')->values()->all();
        $hash = hash('sha256', json_encode([$id, $data['deliveryReference'], $data['receivedDate'], $data['notes'] ?? '', $data['lines'], ...(!empty($data['paymentDueDate']) || !empty($data['supplierDocument']) ? [$data['paymentDueDate'] ?? null, $data['supplierDocument'] ?? null] : [])]));

        return DB::transaction(function () use ($request, $id, $data, $hash) {
            InventoryRules::lockActor($request);
            DB::table('inventory_sequences')->where('name', 'purchase_orders')->lockForUpdate()->first();
            $sourceIds=DB::table('receiving_carryovers')->where('targetPo',$id)->pluck('sourcePo')->push($id)->unique()->all();
            $lockedOrders=DB::table('purchase_orders')->whereIn('id',$sourceIds)->orderBy('id')->lockForUpdate()->get();
            $order=$lockedOrders->firstWhere('id',$id);
            abort_unless($order, 404, 'Purchase order not found.');
            $existing = DB::table('receiving_records')->where('idempotencyKey', $data['idempotencyKey'])->first();
            if ($existing) {
                $linkedDelivery = DB::table('receiving_carryovers')->where('sourcePo', $id)->where('targetPo', $existing->poId)->exists();
                abort_unless(($existing->poId === $id || $linkedDelivery) && $existing->payloadHash === $hash, 409, 'This receiving key was already used for another submission.');

                return response()->json(['message' => 'Delivery was already recorded; inventory was not added again.', 'id' => $existing->id, 'poId' => $existing->poId, 'status'=>DB::table('purchase_orders')->where('id', $existing->poId)->value('status')]);
            }
            abort_unless(DB::table('purchase_order_lines')->where('poId', $id)->get()->contains(fn ($line) => ReceivingCarryover::remaining($line) > 0), 409, 'This purchase order is fully received.');
            $purchaseRequest = DB::table('item_requests')->where('id', $order->itemRequestId)->first();
            abort_unless($purchaseRequest && in_array($purchaseRequest->status, [PurchaseWorkflow::WAITING, 'Partially Received', 'Approved', 'Completed', 'Received with Discrepancy']), 409, 'The linked purchase request is not approved.');
            // The previous approval endpoint created Draft orders from approved requests.
            $approvedLegacyDraft = $order->status === 'Draft' && $purchaseRequest->status === 'Approved';
            abort_unless($approvedLegacyDraft || in_array($order->status, [PurchaseWorkflow::WAITING, 'Partially Received', 'Approved', 'Ordered', 'Completed', 'Received with Discrepancy']), 409, 'Only an approved PO awaiting delivery can receive stock.');
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
            $replacementReasons = [];
            $damageTotal=0;
            foreach ($data['lines'] as $line) {
                $ordered = $orderLines->firstWhere('productId', $line['productId']);
                if (! $ordered) {
                    throw ValidationException::withMessages(['lines' => 'A delivered item does not belong to this PO.']);
                }
                $damage=(int)($line['damagedQty'] ?? 0);
                abort_if($line['qty']+$damage===0,422,'Enter an accepted or damaged quantity.');
                abort_if($damage && !trim($line['damageReason'] ?? ''),422,'Describe the damaged items.');
                abort_if($damage && $damage + $line['qty'] > ReceivingCarryover::remaining($ordered),422,'Damaged quantity exceeds the outstanding quantity.');
                $reserved=ReceivingCarryover::reserved($ordered->id);
                abort_if($reserved && $line['qty']>ReceivingCarryover::remaining($ordered)-$reserved,422,'These units are allocated to another PO. Receive them there or release the allocation first.');
                abort_if($ordered->resolvedQty && $line['qty']>ReceivingCarryover::remaining($ordered),422,'Some units were fulfilled on a later PO. Receive only the remaining balance.');
                $damageTotal+=$damage;
                $substitute = ! empty($line['substituteProductId']);
                $product = InventoryRules::product($line['substituteProductId'] ?? $line['productId'], 'lines');
                if ($substitute) {
                    $replacementReasons[] = ucfirst($line['substitutionReason'] ?? '').' replacement';
                    $limit = (int) round((float) $ordered->unitCost * 100);
                    $actualCost = $line['unitCost'] ?? $product->unitPrice;
                    if ($product->id == $ordered->productId || empty($line['substitutionReason'])
                        || $line['qty'] > ReceivingCarryover::remaining($ordered)
                        || (int) round(InventoryRules::unitCost($product) * 100) > $limit
                        || (int) round((float) $actualCost * 100) > $limit) {
                        throw ValidationException::withMessages(['lines' => 'Select an equal-or-lower-cost replacement, provide its missing/damaged reason, and receive only the outstanding quantity.']);
                    }
                }
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
                $cost = (float) ($line['unitCost'] ?? ($substitute ? $product->unitPrice : $ordered->unitCost));
                $carried=ReceivingCarryover::resolve($ordered,$line['qty'],$cost,$receiptId);
                $receivedCents += (int) round(InventoryRules::lineTotal($line['qty']-$carried, $cost) * 100);
                $batch='BATCH-'.Str::ulid();
                $line['batchNumber']=$line['qty']>0?$batch:null; $line['lotNumber']=$line['batchNumber'];
                if ($line['qty']>0) {
                FifoInventory::opening($product->id, (int) $product->stock);
                FifoInventory::receive($product->id, $line['qty'], $cost, $data['receivedDate'], $receiptId, $order->supplierId, 'purchase_receiving', $line['batchNumber'] ?? null, $line['expiryDate'] ?? null, $line['manufacturedDate'] ?? null, $line['lotNumber'] ?? null);
                $sellingPrice = (float) ($line['sellingPrice'] ?? $product->price);
                DB::table('products')->where('id', $product->id)->update(['stock' => $after, 'unitPrice' => $cost, 'price' => $sellingPrice]);
                if ((float) $product->price !== $sellingPrice || (float) $product->unitPrice !== $cost) {
                    InventoryRules::audit($request->user()->name, 'Prices set on receipt', (string) $product->id,
                        json_encode(['price' => $product->price, 'unitPrice' => $product->unitPrice]),
                        json_encode(['price' => $sellingPrice, 'unitPrice' => $cost, 'receiptId' => $receiptId]));
                }
                StockMovement::record($product->id, $product->stock, $after, 'purchase_receiving', $receiptId, $request->user());
                }
                DB::table('purchase_order_lines')->where('id', $ordered->id)->update(['deliveredQty' => $received]);
                $ordered->deliveredQty = $received;
                DB::table('receiving_record_lines')->insert([
                    'receivingId' => $receiptId, 'productId' => $product->id, 'poQty' => $ordered->orderedQty,
                    'deliveredQty' => $line['qty'], 'name' => $product->name, 'category' => $product->category,
                    'unit' => $ordered->unit ?? ($product->stockUnit ?: $product->unit), 'conditionText' => $substitute ? ucfirst($line['substitutionReason']).' replacement for product #'.$ordered->productId.' ('.$ordered->name.')' : ($damage ? 'Damaged units returned, not received: '.$damage : 'Accepted'),
                    'internalBatch' => $line['qty']>0 ? $batch : null, 'unitCost' => $cost, 'batchNumber' => $line['batchNumber'] ?? null, 'expiryDate' => $line['expiryDate'] ?? null,
                    'manufacturedDate' => $line['manufacturedDate'] ?? null,
                    'lotNumber' => $line['lotNumber'] ?? null,
                ]);
            }
            $matches = $orderLines->every(fn ($line) => (int) $line->deliveredQty + (int)$line->resolvedQty === (int) $line->orderedQty);
            $short = $orderLines->contains(fn ($line) => $line->deliveredQty + $line->resolvedQty < $line->orderedQty);
            // Snapshot every PO line, including items absent from this delivery.
            // Later deliveries must not rewrite this inspection's missing quantities.
            $inspection = $orderLines->map(function ($line) use ($data) {
                $input=collect($data['lines'])->firstWhere('productId',$line->productId);
                $damaged=(int)($input['damagedQty'] ?? 0);
                return [
                    'productId'=>$line->productId, 'name'=>$line->name, 'unit'=>$line->unit,
                    'ordered'=>(int)$line->orderedQty, 'received'=>(int)$line->deliveredQty,
                    'outstanding'=>ReceivingCarryover::remaining($line),
                    'missing'=>max(0,ReceivingCarryover::remaining($line)-$damaged),
                    'damaged'=>$damaged, 'damageReason'=>$input['damageReason'] ?? null,
                    'excess'=>max(0,(int)$line->deliveredQty-(int)$line->orderedQty),
                ];
            })->values()->all();
            foreach ($orderLines as $item) {
                $input=collect($data['lines'])->firstWhere('productId',$item->productId);
                DB::table('receiving_inspections')->insert(['receiptId'=>$receiptId,'poId'=>$id,'orderLine'=>$item->id,'productId'=>$item->productId,'accepted'=>$input['qty'] ?? 0,'damaged'=>$input['damagedQty'] ?? 0,'missing'=>max(0,ReceivingCarryover::remaining($item)-($input['damagedQty'] ?? 0)),'newMissing'=>max(0,ReceivingCarryover::remaining($item)-($input['damagedQty'] ?? 0)-(int)DB::table('receiving_carryovers')->where('targetLine',$item->id)->selectRaw('COALESCE(SUM(quantity-receivedQty-releasedQty),0) as qty')->value('qty')),'damageReason'=>$input['damageReason'] ?? null,'created_at'=>now()]);
            }
            $remarks = ReceivingRemarks::forReceipt((object) ['inspection'=>$inspection, 'outcome'=>$data['notes'] ?? '']);
            $status = $short ? 'Partially Received' : 'Fully Received';
            $deliveredValue = ((int) round((float) $order->deliveredValue * 100) + $receivedCents) / 100;
            if ($deliveredValue > 9999999999.99) {
                throw ValidationException::withMessages(['lines' => 'Received value exceeds the supported amount.']);
            }
            DB::table('receiving_records')->where('id', $receiptId)->update([
                'id' => $receiptId, 'poId' => $id, 'date' => $data['receivedDate'], 'received_at' => now(),
                'deliveryStatus' => 'Completed', 'discrepancy' => ! $matches || $damageTotal>0 || count($replacementReasons) > 0, 'discrepancyType' => implode('; ', array_unique(array_filter([collect($inspection)->sum('missing')>0 ? 'Missing items' : ($matches || $short ? null : 'Quantity difference'), $damageTotal?'Damaged items':null, ...$replacementReasons]))) ?: null,
                'inspection' => json_encode($inspection),
                'outcome' => $data['notes'] ?? '', 'adminApproval' => 'Posted by Manager', 'barcodeAssignment' => 'Existing inventory barcode',
                'idempotencyKey' => $data['idempotencyKey'], 'deliveryReference' => $data['deliveryReference'], 'payloadHash' => $hash,
                ...(\App\Services\SupplierAccounts::ready() ? ['paymentDueDate' => $data['paymentDueDate'] ?? null, 'supplierDocument' => $data['supplierDocument'] ?? null] : []),
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
            ], $request->user(), 'Stock received', 'PO '.$id.': '.$status.'. Receipt '.$data['deliveryReference'].' recorded by '.$request->user()->name.'. Remarks: '.$remarks, 'receiving', $id);

            return response()->json(['message' => 'Receipt posted under '.$id.'. PO: '.$status.'. Only accepted quantities were added to inventory. Remarks: '.$remarks, 'id' => $receiptId, 'poId' => $id, 'status' => $status, 'remarks'=>$remarks, 'inspection' => $inspection], 201);
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

    public function receiptReport(Request $request, string $id)
    {
        $receipt = DB::table('receiving_records')->where('id', $id)->first();
        abort_unless($receipt, 404);
        $request->query->set('receipt', $id);
        return $this->report($request, $receipt->poId);
    }

    public function supplierOrder(Request $request, string $id)
    {
        $order = DB::table('purchase_orders')->where('id', $id)->first();
        abort_unless($order, 404);
        $supplier = DB::table('suppliers')->where('id', $order->supplierId)->first();
        $lines = DB::table('purchase_order_lines')->where('poId', $id)->get();
        return response()->view('supplier-order', compact('order', 'supplier', 'lines'))->header('Cache-Control', 'private, no-store');
    }

    public function report(Request $request, string $id)
    {
        InventoryRules::authorize($request);
        $order = DB::table('purchase_orders')->where('id', $id)->first();
        abort_unless($order, 404);
        $purchase = DB::table('item_requests')->where('id', $order->itemRequestId)->first();
        $supplier = DB::table('suppliers')->where('id', $order->supplierId)->first();
        $lines = DB::table('purchase_order_lines')->where('poId', $id)->orderBy('id')->get();
        $receipts = DB::table('receiving_records')->where('poId', $id)->when($request->filled('receipt'), fn ($q) => $q->where('id', $request->input('receipt')))->orderBy('received_at')->orderBy('id')->get();
        $receiptLines = DB::table('receiving_record_lines')->whereIn('receivingId', $receipts->pluck('id'))->get()->groupBy('receivingId');
        $batchDetails=DB::table('inventory_batches')->whereIn('receiptId',$receipts->pluck('id'))->get()->keyBy(fn($b)=>$b->receiptId.'|'.$b->productId.'|'.$b->batchNumber);
        foreach($receiptLines as $group) foreach($group as $line) {
            $line->manufacturer=$batchDetails->get($line->receivingId.'|'.$line->productId.'|'.$line->batchNumber)?->manufacturer;
        }
        $supplierContacts = DB::table('supplier_contacts')->whereIn('receiptId', $receipts->pluck('id'))->orderBy('id')->get()->groupBy('receiptId');

        $carryovers=DB::table('receiving_carryovers')->where('sourcePo',$id)->orWhere('targetPo',$id)->get();
        $resolutions=DB::table('receiving_carryover_resolutions')->whereIn('carryoverId',$carryovers->pluck('id'))->get();
        return response()->view('purchase-report', compact('carryovers','resolutions','order', 'purchase', 'supplier', 'lines', 'receipts', 'receiptLines', 'supplierContacts'))->header('Cache-Control', 'private, no-store');
    }
}
