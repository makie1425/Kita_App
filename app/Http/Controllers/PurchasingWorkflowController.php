<?php

namespace App\Http\Controllers;

use App\Services\InventoryRules;
use App\Services\SupplierAccounts;
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
            $order = DB::table('purchase_orders')->where('id', $id)->lockForUpdate()->first();
            abort_unless($order, 404);
            abort_if(in_array($order->status, ['Cancelled', 'Declined', 'Draft']), 422, 'Only approved purchase orders can be scheduled or shared.');
            abort_if(!empty($data['supplierSentDate']) && $data['supplierSentDate'] < substr($order->created, 0, 10), 422, 'Sent date cannot precede the order.');
            abort_if(!empty($data['expectedDeliveryDate']) && $data['expectedDeliveryDate'] < substr($order->created, 0, 10), 422, 'Expected delivery cannot precede the order.');
            if (empty($data['supplierSentDate'])) $data['supplierSentVia'] = null;
            DB::table('purchase_orders')->where('id', $id)->update($data);
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
            $order=DB::table('purchase_orders')->where('id',$id)->lockForUpdate()->first();
            abort_unless($order,404);
            if ($order->status==='Cancelled') return response()->json(['message'=>'Order remainder was already cancelled.']);
            $lines=DB::table('purchase_order_lines')->where('poId',$id)->lockForUpdate()->get();
            abort_unless($lines->contains(fn ($l)=>\App\Services\ReceivingCarryover::remaining($l)>0),422,'There is no outstanding quantity to cancel.');
            $links=DB::table('receiving_carryovers')->where(fn ($q)=>$q->where('sourcePo',$id)->orWhere('targetPo',$id))->get();
            abort_if($links->contains(fn ($a)=>$a->quantity>$a->receivedQty+$a->releasedQty),409,'Release unreceived legacy allocations before cancelling.');
            $value=$lines->sum(fn ($l)=>round(\App\Services\ReceivingCarryover::remaining($l)*$l->unitCost,2));
            DB::table('purchase_orders')->where('id',$id)->update(['status'=>'Cancelled','cancelledValue'=>$value,'outstandingValue'=>0,'receivingVersion'=>$order->receivingVersion+1]);
            DB::table('item_requests')->where('id',$order->itemRequestId)->update(['status'=>'Cancelled','adminNote'=>$data['reason']]);
            InventoryRules::audit($request->user()->name,'Cancelled outstanding purchase quantities',$id,json_encode($order),$data['reason']);
            return response()->json(['message'=>'Outstanding quantities cancelled. Accepted stock, receipts and supplier balances remain recorded.']);
        },3);
    }

    public function accounts(Request $request)
    {
        return response()->json([
            'receipts'=>SupplierAccounts::receipts()->orderByDesc('r.received_at')->orderByDesc('r.id')->get()->map(fn ($r)=>SupplierAccounts::describe($r)),
            'settlements'=>DB::table('supplier_settlements')->orderByDesc('id')->get(['id','receiptId','kind','date','amount','reference','notes','actor','created_at','voidedAt','voidReason']),
        ])->header('Cache-Control', 'no-store');
    }

    public function settlement(Request $request, string $id)
    {
        $data = $request->validate([
            'requestKey'=>['required', 'uuid'], 'kind'=>['required', 'in:Payment,Credit note'],
            'date'=>['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'amount'=>['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
            'reference'=>['required', 'string', 'max:100'], 'notes'=>['nullable', 'string', 'max:2000'],
        ]);
        $data['amount'] = number_format((float) $data['amount'], 2, '.', '');
        $data['reference'] = trim($data['reference']);
        abort_unless($data['reference'] !== '', 422, 'Enter the payment or credit note reference.');
        $hash = hash('sha256', json_encode([$id, $data]));
        return DB::transaction(function () use ($request, $id, $data, $hash) {
            InventoryRules::lockActor($request);
            $receipt = DB::table('receiving_records')->where('id', $id)->lockForUpdate()->first();
            abort_unless($receipt, 404);
            $previous = DB::table('supplier_settlements')->where('requestKey', $data['requestKey'])->first();
            if ($previous) {
                abort_unless($previous->payloadHash === $hash && $previous->actorId == $request->user()->id, 409, 'This submission key was already used.');
                return response()->json(['message'=>'Entry already recorded.']);
            }
            $account = SupplierAccounts::describe(SupplierAccounts::receipts()->where('r.id', $id)->first());
            abort_if($account->unknownCosts || $account->legacyPaidValue > 0, 409, 'Reconcile legacy receipt costs and payments before recording settlement.');
            abort_if($data['date'] < $receipt->date, 422, 'Entry date cannot precede the receipt.');
            abort_if((int) round((float) $data['amount'] * 100) > (int) round($account->balance * 100), 422, 'Amount exceeds the unpaid receipt balance.');
            abort_if(DB::table('supplier_settlements')->where('receiptId', $id)->where('kind', $data['kind'])->where('reference', $data['reference'])->whereNull('voidedAt')->exists(), 409, 'This reference is already recorded for this receipt.');
            $entry = DB::table('supplier_settlements')->insertGetId($data + ['receiptId'=>$id, 'payloadHash'=>$hash, 'actorId'=>$request->user()->id, 'actor'=>$request->user()->name, 'created_at'=>now()]);
            InventoryRules::audit($request->user()->name, 'Recorded supplier '.$data['kind'], (string) $entry, null, json_encode($data));
            return response()->json(['message'=>'Supplier entry recorded. No money transfer was made.', 'id'=>$entry], 201);
        }, 3);
    }

    public function voidSettlement(Request $request, int $id)
    {
        $data = $request->validate(['reason'=>['required', 'string', 'max:2000']]);
        abort_unless(trim($data['reason']), 422, 'Enter a void reason.');
        return DB::transaction(function () use ($request, $id, $data) {
            InventoryRules::lockActor($request);
            $entry = DB::table('supplier_settlements')->where('id', $id)->first();
            abort_unless($entry, 404);
            DB::table('receiving_records')->where('id', $entry->receiptId)->lockForUpdate()->first();
            $entry = DB::table('supplier_settlements')->where('id', $id)->lockForUpdate()->first();
            if (!$entry->voidedAt) {
                DB::table('supplier_settlements')->where('id', $id)->update(['voidedAt'=>now(), 'voidReason'=>$data['reason']]);
                InventoryRules::audit($request->user()->name, 'Voided supplier entry', (string) $id, json_encode($entry), $data['reason']);
            }
            return response()->json(['message'=>'Entry voided; its audit history is retained.']);
        });
    }
}
