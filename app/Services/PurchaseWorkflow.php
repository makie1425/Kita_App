<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PurchaseWorkflow
{
    public const WAITING = 'Approved / Waiting for Delivery';

    public function create(Request $request)
    {
        InventoryRules::authorize($request);
        $data = $request->validate([
            'id' => ['prohibited'],
            'submissionKey' => ['sometimes', 'required', 'uuid'],
            'category' => ['required', 'string', Rule::exists('categories', 'name')->where('status', 'Active')],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.productId' => ['required', 'integer', 'min:1'],
            'lines.*.qty' => ['required', 'integer', 'between:1,1000000'],
            'lines.*.supplierId' => ['nullable', 'integer'],
        ]);
        $hash = hash('sha256', json_encode($data));

        return DB::transaction(function () use ($request, $data, $hash) {
            InventoryRules::lockActor($request);
            $sequence = DB::table('inventory_sequences')->where('name', 'purchase_orders')->lockForUpdate()->first();
            if (isset($data['submissionKey'])) {
                $existing = DB::table('item_requests')->where('submissionKey', $data['submissionKey'])->first();
                if ($existing) {
                    abort_unless($existing->submissionHash === $hash && (int) $existing->requestedById === (int) $request->user()->id
                        && $existing->requestedByRole === $request->user()->role, 409, 'This submission key is already used. Refresh the requests list.');

                    return response()->json(['message' => 'Purchase request already saved.', 'id' => $existing->id, 'status' => $existing->status]);
                }
            }
            $number = (int) $sequence->lastValue;
            // Keep the existing sequence across years; never renumber historical records.
            $prefix = 'PO-'.now()->format('Y').'-';
            do {
                abort_if($number >= PHP_INT_MAX - 1, 409, 'Purchase order numbering limit reached.');
                $id = $prefix.str_pad((string) ++$number, 6, '0', STR_PAD_LEFT);
            } while (DB::table('item_requests')->where('id', $id)->exists() || DB::table('purchase_orders')->where('id', $id)->exists());
            DB::table('inventory_sequences')->where('name', 'purchase_orders')->update(['lastValue' => $number]);
            $lines = [];
            foreach (collect($data['lines'])->sortBy('productId') as $line) {
                $product = InventoryRules::product($line['productId'], 'lines');
                $supplierId = $line['supplierId'] ?? $product->supplierId;
                $supplier = DB::table('suppliers')->where('id', $supplierId)->where('status', 'Active')->whereNull('archivedAt')->first();
                if (! $supplier) {
                    throw ValidationException::withMessages(['lines' => 'Select an active supplier for every item.']);
                }
                if ($lines && (int) reset($lines)['supplierId'] !== (int) $supplierId) {
                    throw ValidationException::withMessages(['lines' => 'Use one supplier per PO. Submit a separate request for another supplier.']);
                }
                $qty = ($lines[$product->id]['qty'] ?? 0) + $line['qty'];
                if ($qty > 1000000) {
                    throw ValidationException::withMessages(['lines' => 'Total quantity per item must not exceed 1,000,000.']);
                }
                $unit = $product->stockUnit ?: $product->unit;
                if (! $unit) {
                    throw ValidationException::withMessages(['lines' => 'Set the inventory unit before requesting this item.']);
                }
                $lines[$product->id] = ['requestId' => $id, 'productId' => $product->id, 'name' => InventoryRules::productLabel($product),
                    'category' => $product->category, 'unit' => $unit, 'qty' => $qty, 'supplierId' => $supplierId,
                    'unitCost' => InventoryRules::unitCost($product)];
            }
            DB::table('item_requests')->insert([
                'id' => $id, 'category' => $data['category'], 'status' => 'Pending Approval', 'dateRequested' => now()->toDateString(),
                'requested_at' => now(), 'requestedBy' => $request->user()->name, 'requestedById' => $request->user()->id,
                'requestedByRole' => $request->user()->role, 'supplierName' => $supplier->name, 'notes' => $data['notes'] ?? null,
                'submissionKey' => $data['submissionKey'] ?? null, 'submissionHash' => $hash,
            ]);
            DB::table('item_request_lines')->insert(array_values($lines));
            InventoryRules::audit($request->user()->name, 'Submitted purchase request', $id, null, 'Pending Approval');
            WorkflowNotifications::send([...WorkflowNotifications::reviewers(), $request->user()], $request->user(), 'Purchase request submitted',
                $request->user()->name.' submitted PO '.$id.' for approval.', 'purchase', $id);

            return response()->json(['message' => 'Purchase request submitted.', 'id' => $id, 'status' => 'Pending Approval'], 201);
        }, 3);
    }

    public function review(Request $request, string $id)
    {
        InventoryRules::authorize($request, ['admin', 'superadmin', 'super_admin']);
        $data = $request->validate([
            'action' => ['required', 'in:approved,disapproved,proceed,modify'],
            'note' => ['required_if:action,disapproved', 'nullable', 'string', 'max:2000'],
            'revision' => ['sometimes', 'integer', 'min:0'],
            'lines' => ['required_if:action,modify', 'array', 'min:1', 'max:500'],
            'lines.*.productId' => ['required', 'integer', 'distinct'],
            'lines.*.qty' => ['required', 'integer', 'between:1,1000000'],
        ]);

        return DB::transaction(function () use ($request, $data, $id) {
            InventoryRules::lockActor($request);
            $record = DB::table('item_requests')->where('id', $id)->lockForUpdate()->first();
            abort_unless($record, 404);
            abort_unless(in_array($record->status, ['Pending', 'Pending Approval', 'Proceed to Purchase']) && ! $record->poId, 409, 'This request has already been reviewed.');
            if (isset($data['revision'])) {
                abort_unless($record->revision === $data['revision'], 409, 'The request changed. Refresh before reviewing.');
            }
            $before = ['request' => $record, 'lines' => DB::table('item_request_lines')->where('requestId', $id)->get()];
            if (isset($data['lines'])) {
                $original = $before['lines']->keyBy('productId');
                $changed = count($data['lines']) !== $original->count();
                foreach ($data['lines'] as $line) {
                    $old = $original->get($line['productId']);
                    $changed = $changed || ! $old || (int) ($old->confirmedQty ?? $old->qty) !== (int) $line['qty'];
                }
                if ($changed && trim($data['note'] ?? '') === '') {
                    throw ValidationException::withMessages(['note' => 'Enter a reason for changing purchase quantities or removing items.']);
                }
                foreach ($data['lines'] as $line) {
                    $query = DB::table('item_request_lines')->where('requestId', $id)->where('productId', $line['productId']);
                    if (! $query->exists()) {
                        throw ValidationException::withMessages(['lines' => 'An edited item is not in this request.']);
                    }
                    $query->update(['confirmedQty' => $line['qty']]);
                }
                // The submitted edit is the complete set of retained lines.
                DB::table('item_request_lines')->where('requestId', $id)->whereNotIn('productId', array_column($data['lines'], 'productId'))->delete();
            }
            $status = match ($data['action']) {
                'approved' => self::WAITING, 'disapproved' => 'Declined', default => 'Pending Approval',
            };
            $poId = null;
            if ($data['action'] === 'approved') {
                $lines = DB::table('item_request_lines')->where('requestId', $id)->orderBy('productId')->get();
                $suppliers = $lines->pluck('supplierId')->unique();
                $supplier = $suppliers->count() === 1 ? DB::table('suppliers')->where('id', $suppliers->first())->where('status', 'Active')->whereNull('archivedAt')->first() : null;
                if (! $supplier || $lines->isEmpty()) {
                    throw ValidationException::withMessages(['lines' => 'A PO requires items from one active supplier.']);
                }
                $poId = $record->id;
                abort_if(DB::table('purchase_orders')->where('id', $poId)->exists(), 409, 'This PO ID is already in use.');
                $orderLines = [];
                $total = 0;
                foreach ($lines as $line) {
                    $product = InventoryRules::product($line->productId, 'lines');
                    $qty = $line->confirmedQty ?? $line->qty;
                    $cost = $line->unitCost ?? InventoryRules::unitCost($product);
                    $amount = InventoryRules::lineTotal($qty, $cost);
                    $total += $amount;
                    $orderLines[] = ['poId' => $poId, 'productId' => $line->productId, 'name' => $line->name,
                        'category' => $line->category ?? $product->category, 'unit' => $line->unit ?? ($product->stockUnit ?: $product->unit),
                        'orderedQty' => $qty, 'unitCost' => $cost, 'lineTotal' => $amount, 'deliveredQty' => 0];
                }
                if ($total > 9999999999.99) {
                    throw ValidationException::withMessages(['lines' => 'The order amount is too large.']);
                }
                DB::table('purchase_orders')->insert([
                    'id' => $poId, 'itemRequestId' => $id, 'supplierId' => $supplier->id, 'supplierName' => $record->supplierName ?? $supplier->name,
                    'status' => self::WAITING, 'orderedValue' => round($total, 2), 'outstandingValue' => round($total, 2),
                    'deliveredValue' => 0, 'invoicedValue' => 0, 'paidValue' => 0, 'cancelledValue' => 0,
                    'receivingRecordIds' => '[]', 'created' => now()->toDateString(), 'createdById' => $request->user()->id, 'createdByRole' => $request->user()->role,
                ]);
                DB::table('purchase_order_lines')->insert($orderLines);
            }
            $values = ['status' => $status, 'adminNote' => filled($data['note'] ?? null) ? trim($data['note']) : ($record->adminNote ?? ''), 'revision' => $record->revision + 1,
                'disapprovalReason' => $status === 'Declined' ? $data['note'] : null, 'poId' => $poId];
            if (in_array($data['action'], ['approved', 'disapproved'])) {
                $values += ['reviewed_at' => now(), 'approved_at' => $poId ? now() : null, 'approvedById' => $request->user()->id,
                    'approvedByRole' => $request->user()->role, 'approvedBy' => $request->user()->name];
            }
            DB::table('item_requests')->where('id', $id)->update($values);
            InventoryRules::audit($request->user()->name, 'Purchase review: '.$data['action'], $id, json_encode($before), json_encode($data));
            $label = match ($data['action']) {
                'approved' => 'Approved', 'disapproved' => 'Declined', default => 'Updated'
            };
            WorkflowNotifications::send([WorkflowNotifications::owner($record->requestedById, $record->requestedByRole), $request->user()], $request->user(),
                'Purchase request '.$label, 'PO '.$id.' was '.strtolower($label).' by '.$request->user()->name.'.'.(! empty($data['note']) ? ' '.$data['note'] : ''), 'purchase', $id);

            return response()->json(['message' => 'Purchase request updated.', 'id' => $id, 'status' => $status, 'poId' => $poId]);
        }, 3);
    }
}
