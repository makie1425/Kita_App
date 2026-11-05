<?php

namespace App\Http\Controllers;

use App\Services\InventoryRules;
use App\Services\SupplierAccounts;
use App\Services\WorkspaceRead;
use App\Services\WorkspaceWrite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchasingWorkflowController extends Controller
{
    public function plan(Request $request, string $id)
    {
        SupplierAccounts::requireReady();
        $data = $request->validate([
            'expectedDeliveryDate'=>['nullable', 'date_format:Y-m-d'],
            'supplierSentDate'=>['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'supplierSentVia'=>['required_with:supplierSentDate', 'nullable', 'in:Email,Printed copy,Phone,Online portal,In person'],
            'supplierSentNote'=>['nullable', 'string', 'max:2000'],
        ]);
        return DB::transaction(function () use ($request, $id, $data) {
            InventoryRules::lockActor($request);
            $order = WorkspaceRead::table('purchase_orders')->where('id', $id)->lockForUpdate()->first();
            abort_unless($order, 404);
            abort_if(in_array($order->status, ['Cancelled', 'Declined', 'Draft']), 422, 'Only approved purchase orders can be scheduled or shared.');
            abort_if(!empty($data['supplierSentDate']) && $data['supplierSentDate'] < substr($order->created, 0, 10), 422, 'Sent date cannot precede the order.');
            abort_if(!empty($data['expectedDeliveryDate']) && $data['expectedDeliveryDate'] < substr($order->created, 0, 10), 422, 'Expected delivery cannot precede the order.');
            if (empty($data['supplierSentDate'])) $data['supplierSentVia'] = null;
            WorkspaceRead::table('purchase_orders')->where('id', $id)->update($data);
            InventoryRules::audit($request->user()->name, 'Updated purchase delivery plan', $id, json_encode($order), json_encode($data));
            return response()->json(['message'=>'Delivery plan saved. Supplier communication is recorded; no message was sent by KITA.']);
        });
    }

    public function cancelRemainder(Request $request, string $id)
    {
        $data=$request->validate(['reason'=>['required','string','max:2000']]);
        abort_unless(trim($data['reason']),422,'Enter the cancellation reason.');
        return DB::transaction(function () use ($request,$id,$data) {
            InventoryRules::lockActor($request);
            DB::table('inventory_sequences')->where('name','purchase_orders')->lockForUpdate()->first();
            $order=WorkspaceRead::table('purchase_orders')->where('id',$id)->lockForUpdate()->first();
            abort_unless($order,404);
            if ($order->status==='Cancelled') return response()->json(['message'=>'Order remainder was already cancelled.']);
            $lines=WorkspaceRead::table('purchase_order_lines')->where('poId',$id)->lockForUpdate()->get();
            abort_unless($lines->contains(fn ($l)=>\App\Services\ReceivingCarryover::remaining($l)>0),422,'There is no outstanding quantity to cancel.');
            abort_if(WorkspaceRead::table('receiving_records')->where('poId',$id)->exists(), 422, 'Resolve missing or damaged goods through Stock Receiving before closing this PO. Supplier obligations cannot be cancelled.');
            $links=WorkspaceRead::table('receiving_carryovers')->where(fn ($q)=>$q->where('sourcePo',$id)->orWhere('targetPo',$id))->get();
            abort_if($links->contains(fn ($a)=>$a->quantity>$a->receivedQty+$a->releasedQty),409,'Release unreceived legacy allocations before cancelling.');
            $value=$lines->sum(fn ($l)=>round(\App\Services\ReceivingCarryover::remaining($l)*$l->unitCost,2));
            WorkspaceRead::table('purchase_orders')->where('id',$id)->update(['status'=>'Cancelled','cancelledValue'=>$value,'outstandingValue'=>0,'receivingVersion'=>$order->receivingVersion+1]);
            WorkspaceRead::table('item_requests')->where('id',$order->itemRequestId)->update(['status'=>'Cancelled','adminNote'=>$data['reason']]);
            InventoryRules::audit($request->user()->name,'Cancelled outstanding purchase quantities',$id,json_encode($order),$data['reason']);
            return response()->json(['message'=>'Outstanding quantities cancelled. Accepted stock, receipts and supplier balances remain recorded.']);
        },3);
    }

    public function accounts(Request $request)
    {
        return response()->json([
            'balances'=>SupplierAccounts::balances(),
            'receipts'=>SupplierAccounts::receipts()->when(config('workspaces.enabled'), fn ($query) => $query->where('r.workspace_id', WorkspaceWrite::requireId())->where('po.workspace_id', WorkspaceWrite::requireId()))->orderByDesc('r.received_at')->orderByDesc('r.id')->get()->map(fn ($r)=>SupplierAccounts::describe($r)),
            'settlements'=>WorkspaceRead::table('supplier_settlements')->orderByDesc('id')->get(['id','receiptId','kind','date','amount','reference','notes','actor','created_at','voidedAt','voidReason']),
        ])->header('Cache-Control', 'no-store');
    }

    public function settlement(Request $request, string $id)
    {
        abort(422, 'Accepted stock is treated as paid on receipt. Use Stock Receiving to settle missing or damaged goods.');
    }

    public function voidSettlement(Request $request, int $id)
    {
        $data = $request->validate(['reason'=>['required', 'string', 'max:2000']]);
        abort_unless(trim($data['reason']), 422, 'Enter a void reason.');
        return DB::transaction(function () use ($request, $id, $data) {
            InventoryRules::lockActor($request);
            $entry = WorkspaceRead::table('supplier_settlements')->where('id', $id)->first();
            abort_unless($entry, 404);
            abort_unless(WorkspaceRead::table('receiving_records')->where('id', $entry->receiptId)->lockForUpdate()->first(), 404);
            $entry = WorkspaceRead::table('supplier_settlements')->where('id', $id)->lockForUpdate()->first();
            if (!$entry->voidedAt) {
                WorkspaceRead::table('supplier_settlements')->where('id', $id)->update(['voidedAt'=>now(), 'voidReason'=>$data['reason']]);
                InventoryRules::audit($request->user()->name, 'Voided supplier entry', (string) $id, json_encode($entry), $data['reason']);
            }
            return response()->json(['message'=>'Entry voided; its audit history is retained.']);
        });
    }
}
