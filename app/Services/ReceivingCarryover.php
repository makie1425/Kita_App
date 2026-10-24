<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReceivingCarryover
{
    public static function remaining(object $line): int
    {
        return max(0, $line->orderedQty - $line->deliveredQty - ($line->resolvedQty ?? 0));
    }

    public static function originalRemaining(object $line): int
    {
        $incoming=(int)DB::table('receiving_carryovers')->where('targetLine',$line->id)
            ->selectRaw('COALESCE(SUM(quantity-receivedQty-releasedQty),0) as qty')->value('qty');
        return max(0,self::remaining($line)-$incoming);
    }

    public static function reserved(int $line): int
    {
        return (int) DB::table('receiving_carryovers')->where('sourceLine', $line)
            ->selectRaw('COALESCE(SUM(quantity - receivedQty - releasedQty), 0) as qty')->value('qty');
    }

    public static function allocate(Request $request, string $id)
    {
        InventoryRules::authorize($request, ['manager', 'superadmin', 'super_admin']);
        $data = $request->validate(['requestKey'=>['required','uuid'], 'sourceLine'=>['required','integer'], 'targetPo'=>['nullable','string'], 'quantity'=>['required','integer','between:1,1000000']]);
        return DB::transaction(function () use ($request, $id, $data) {
            InventoryRules::lockActor($request);
            // Serialize automatic numbering and retries before locking the orders.
            if (empty($data['targetPo'])) {
                DB::table('inventory_sequences')->where('name', 'purchase_orders')->lockForUpdate()->first();
                $previous = DB::table('receiving_carryovers')->where('requestKey', $data['requestKey'])->first();
                if ($previous) {
                    abort_unless($previous->sourcePo === $id && $previous->sourceLine == $data['sourceLine'] && $previous->quantity == $data['quantity'], 409, 'This allocation key was already used.');
                    return response()->json(['message'=>'Carryover already linked.', 'poId'=>$previous->targetPo]);
                }
                $source = DB::table('purchase_orders')->where('id', $id)->lockForUpdate()->first();
                abort_unless($source && DB::table('receiving_records')->where('poId', $id)->exists(), 422, 'Choose a PO with a recorded shortage.');
                $line = DB::table('purchase_order_lines')->where('poId', $id)->where('id', $data['sourceLine'])->lockForUpdate()->first();
                abort_unless($line && $data['quantity'] <= self::remaining($line)-self::reserved($line->id), 422, 'The shortage quantity is already allocated or no longer outstanding.');
                $data['targetPo'] = self::createDeliveryOrder($request, $source);
            }
            $orders = DB::table('purchase_orders')->whereIn('id', [$id,$data['targetPo']])->orderBy('id')->lockForUpdate()->get();
            $source = $orders->firstWhere('id', $id); $target = $orders->firstWhere('id', $data['targetPo']);
            abort_unless($source && $target && $id !== $target->id && $source->supplierId === $target->supplierId, 422, 'Choose another PO from the same supplier.');
            $existing = DB::table('receiving_carryovers')->where('requestKey', $data['requestKey'])->first();
            if ($existing) {
                abort_unless($existing->sourcePo === $id && $existing->targetPo === $target->id && $existing->sourceLine == $data['sourceLine'] && $existing->quantity == $data['quantity'], 409, 'This allocation key was already used.');
                return response()->json(['message'=>'Carryover already linked.', 'poId'=>$target->id]);
            }
            abort_unless(in_array($target->status, [PurchaseWorkflow::WAITING,'Approved','Ordered']) && !DB::table('receiving_records')->where('poId',$target->id)->exists(), 422, 'Choose an approved subsequent PO before its first receipt.');
            abort_unless(DB::table('receiving_records')->where('poId',$id)->exists() && $target->created >= $source->created && strcmp($target->id, $source->id) > 0, 422, 'Choose a subsequent PO for a recorded shortage.');
            $line = DB::table('purchase_order_lines')->where('poId',$id)->where('id',$data['sourceLine'])->lockForUpdate()->first();
            abort_unless($line && $data['quantity'] <= self::remaining($line)-self::reserved($line->id),422,'The shortage quantity is already allocated or no longer outstanding.');
            $targetLine = DB::table('purchase_order_lines')->where('poId',$target->id)->where('productId',$line->productId)->lockForUpdate()->first();
            if ($targetLine) {
                abort_unless($targetLine->unit === $line->unit && (float)$targetLine->unitCost === (float)$line->unitCost,422,'The target product must use the same stock unit and purchase cost as the original shortage.');
                abort_if($targetLine->orderedQty+$data['quantity']>1000000,422,'Quantity limit exceeded.');
                DB::table('purchase_order_lines')->where('id',$targetLine->id)->update(['orderedQty'=>$targetLine->orderedQty+$data['quantity'],'carryoverQty'=>$targetLine->carryoverQty+$data['quantity']]);
                $targetLineId=$targetLine->id;
            } else {
                $targetLineId=DB::table('purchase_order_lines')->insertGetId(['poId'=>$target->id,'productId'=>$line->productId,'name'=>$line->name,'category'=>$line->category,'unit'=>$line->unit,'orderedQty'=>$data['quantity'],'carryoverQty'=>$data['quantity'],'deliveredQty'=>0,'unitCost'=>$line->unitCost,'lineTotal'=>0]);
            }
            DB::table('receiving_carryovers')->insert(['requestKey'=>$data['requestKey'],'sourcePo'=>$id,'sourceLine'=>$line->id,'targetPo'=>$target->id,'targetLine'=>$targetLineId,'quantity'=>$data['quantity'],'actorId'=>$request->user()->id,'created_at'=>now()]);
            DB::table('purchase_orders')->whereIn('id',[$id,$target->id])->increment('receivingVersion');
            InventoryRules::audit($request->user()->name,'Linked previous shortage',$id,null,json_encode($data));
            return response()->json(['message'=>'Previous shortage added to delivery PO '.$target->id.' without a new purchase charge.', 'poId'=>$target->id],201);
        },3);
    }

    private static function createDeliveryOrder(Request $request, object $source): string
    {
        $original = DB::table('item_requests')->where('id', $source->itemRequestId)->first();
        abort_unless($original && $original->approved_at, 422, 'The original purchase must be approved.');
        $number = (int) DB::table('inventory_sequences')->where('name', 'purchase_orders')->value('lastValue');
        do {
            abort_if($number >= PHP_INT_MAX - 1, 409, 'Purchase order numbering limit reached.');
            $id = 'PO-'.now()->format('Y-m').'-'.str_pad((string) ++$number, 6, '0', STR_PAD_LEFT);
        } while (DB::table('item_requests')->where('id', $id)->exists() || DB::table('purchase_orders')->where('id', $id)->exists());
        DB::table('inventory_sequences')->where('name', 'purchase_orders')->update(['lastValue'=>$number]);
        DB::table('item_requests')->insert([
            'id'=>$id, 'poId'=>$id, 'category'=>$original->category, 'status'=>PurchaseWorkflow::WAITING,
            'dateRequested'=>now()->toDateString(), 'requested_at'=>now(), 'requestedBy'=>$request->user()->name,
            'requestedById'=>$request->user()->id, 'requestedByRole'=>$request->user()->role,
            'supplierName'=>$source->supplierName, 'approved_at'=>$original->approved_at,
            'approvedById'=>$original->approvedById, 'approvedByRole'=>$original->approvedByRole, 'approvedBy'=>$original->approvedBy,
            'notes'=>'Delivery of previously approved shortages from '.$source->id.'. No new purchase charge.',
        ]);
        DB::table('purchase_orders')->insert([
            'id'=>$id, 'itemRequestId'=>$id, 'supplierId'=>$source->supplierId, 'supplierName'=>$source->supplierName,
            'status'=>PurchaseWorkflow::WAITING, 'created'=>now()->toDateString(),
            'createdById'=>$request->user()->id, 'createdByRole'=>$request->user()->role,
            'orderedValue'=>0, 'deliveredValue'=>0, 'outstandingValue'=>0, 'invoicedValue'=>0, 'paidValue'=>0,
            'cancelledValue'=>0, 'receivingRecordIds'=>'[]',
        ]);
        InventoryRules::audit($request->user()->name, 'Created shortage delivery PO', $id, null, $source->id);
        return $id;
    }

    public static function release(Request $request, int $id)
    {
        InventoryRules::authorize($request, ['manager','superadmin','super_admin']);
        return DB::transaction(function () use ($request,$id) {
            InventoryRules::lockActor($request);
            $entry=DB::table('receiving_carryovers')->where('id',$id)->first(); abort_unless($entry,404);
            DB::table('purchase_orders')->whereIn('id',[$entry->sourcePo,$entry->targetPo])->orderBy('id')->lockForUpdate()->get();
            $entry=DB::table('receiving_carryovers')->where('id',$id)->lockForUpdate()->first();
            $remaining=$entry->quantity-$entry->receivedQty-$entry->releasedQty;
            if ($remaining>0) {
                DB::table('receiving_carryovers')->where('id',$id)->increment('releasedQty',$remaining);
                DB::table('purchase_order_lines')->where('id',$entry->targetLine)->decrement('orderedQty',$remaining);
                DB::table('purchase_order_lines')->where('id',$entry->targetLine)->decrement('carryoverQty',$remaining);
                DB::table('purchase_orders')->whereIn('id',[$entry->sourcePo,$entry->targetPo])->increment('receivingVersion');
                $target=DB::table('purchase_orders')->where('id',$entry->targetPo)->first();
                if (DB::table('receiving_records')->where('poId',$target->id)->exists() && DB::table('purchase_order_lines')->where('poId',$target->id)->get()->every(fn($line)=>self::remaining($line)===0)) {
                    DB::table('purchase_orders')->where('id',$target->id)->update(['status'=>'Completed']);
                    DB::table('item_requests')->where('id',$target->itemRequestId)->update(['status'=>'Completed']);
                }
                InventoryRules::audit($request->user()->name,'Released unreceived carryover',$entry->sourcePo,null,json_encode(['allocation'=>$id,'quantity'=>$remaining]));
            }
            return response()->json(['message'=>'Unreceived allocation released; original shortage remains open.']);
        },3);
    }

    // Carryover units are fulfilled first; monetary receipt value belongs to the original obligation.
    public static function resolve(object $line, int $qty, float $cost, string $receipt): int
    {
        $used=0;
        $allocations=DB::table('receiving_carryovers')->where('targetLine',$line->id)->orderBy('id')->lockForUpdate()->get();
        foreach ($allocations as $a) {
            $take=min($qty-$used,$a->quantity-$a->receivedQty-$a->releasedQty);
            if ($take<=0) continue;
            $source=DB::table('purchase_order_lines')->where('id',$a->sourceLine)->lockForUpdate()->first();
            abort_if($take>self::remaining($source),409,'The original shortage changed. Refresh receiving.');
            // A shortage may pass through several deliveries. Settle its original
            // obligation once, while retaining every intermediate delivery link.
            $forwarded = self::resolve($source, $take, $cost, $receipt);
            DB::table('purchase_order_lines')->where('id',$source->id)->increment('resolvedQty',$take);
            DB::table('receiving_carryovers')->where('id',$a->id)->increment('receivedQty',$take);
            DB::table('receiving_carryover_resolutions')->insert(['carryoverId'=>$a->id,'receiptId'=>$receipt,'quantity'=>$take,'unitCost'=>$cost,'created_at'=>now()]);
            $po=DB::table('purchase_orders')->where('id',$a->sourcePo)->lockForUpdate()->first();
            $value=round($po->deliveredValue+($take-$forwarded)*$cost,2);
            abort_if($value>9999999999.99,422,'Received value exceeds the supported amount.');
            PurchaseBudget::check($po,$value);
            $complete=DB::table('purchase_order_lines')->where('poId',$po->id)->get()->every(fn($l)=>self::remaining($l)===0);
            $status=$complete?'Fully Received':'Partially Received';
            DB::table('purchase_orders')->where('id',$po->id)->update(['deliveredValue'=>$value,'outstandingValue'=>max(0,PurchaseBudget::limit($po)-$value),'status'=>$status,'receivingVersion'=>$po->receivingVersion+1]);
            DB::table('item_requests')->where('id',$po->itemRequestId)->update(['status'=>$status]);
            $used+=$take;
        }
        return $used;
    }
}
