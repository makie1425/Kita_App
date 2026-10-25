<?php

namespace App\Services;

use App\Http\Controllers\PurchasingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockReceiptWorkflow
{
    public static function obligation(object $line): array
    {
        $remaining=ReceivingCarryover::originalRemaining($line);
        $damage=min($remaining,(int)($line->damageOutstanding ?? 0));
        return ['missing'=>$remaining-$damage,'damaged'=>$damage,'unclassified'=>$line->damageOutstanding===null && (int)$line->deliveredQty>0,'total'=>$remaining];
    }

    public static function listing()
    {
        return DB::table('stock_receipts')->orderByDesc('created_at')->limit(200)->get()->map(function ($r) {
            foreach (['payload','summary','exceptions','receipts'] as $field) $r->$field=json_decode($r->$field ?? 'null',true);
            unset($r->payloadHash,$r->requestKey);
            return $r;
        });
    }

    private function data(Request $request): array
    {
        return $request->validate([
            'requestKey'=>['required','uuid'],'primaryPo'=>['required','string'], 'supplierId'=>['required','integer'],
            'deliveryDate'=>['required','date_format:Y-m-d','before_or_equal:today'],
            'supplierReference'=>['nullable','string','max:100'],'notes'=>['nullable','string','max:2000'],
            'entries'=>['required','array','min:1','max:200'],
            'entries.*.poId'=>['required','string'],'entries.*.version'=>['required','integer','min:0'],
            'entries.*.productId'=>['required','integer'],'entries.*.type'=>['required','in:current,missing,damaged,outstanding'],
            'entries.*.delivered'=>['required','integer','between:0,1000000'],'entries.*.damaged'=>['required','integer','between:0,1000000'],
            'entries.*.damageReason'=>['nullable','string','max:500'],'entries.*.disposition'=>['nullable','in:return,reject,quarantine'],
            'entries.*.substituteProductId'=>['nullable','integer'],'entries.*.substitutionReason'=>['nullable','string','max:500'],
            'entries.*.unitCost'=>['required',...InventoryRules::moneyRules()],
            'entries.*.sellingPrice'=>['prohibited'],
            'entries.*.lots'=>['present','array','max:100'],
            'entries.*.lots.*.quantity'=>['required','integer','between:1,1000000'],
            'entries.*.lots.*.supplierLot'=>['nullable','string','max:40'],
            'entries.*.lots.*.expiryDate'=>['nullable','date_format:Y-m-d','after:today'],
            'entries.*.lots.*.manufacturedDate'=>['nullable','date_format:Y-m-d','before_or_equal:deliveryDate'],
        ]);
    }

    /** Locks and revalidates the complete receipt; never trusts browser calculations. */
    private function inspect(array $data): array
    {
        DB::table('inventory_sequences')->where('name','purchase_orders')->lockForUpdate()->first();
        $supplier=DB::table('suppliers')->where('id',$data['supplierId'])->where('status','Active')->whereNull('archivedAt')->lockForUpdate()->first();
        abort_unless($supplier,422,'Select an active supplier.');
        $ids=collect($data['entries'])->pluck('poId')->push($data['primaryPo'])->unique()->sort()->values()->all();
        $orders=DB::table('purchase_orders')->whereIn('id',$ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $lines=DB::table('purchase_order_lines')->whereIn('poId',$ids)->orderBy('id')->lockForUpdate()->get();
        $primary=$orders->get($data['primaryPo']);
        abort_unless($primary && (int)$primary->supplierId===(int)$data['supplierId'],422,'Choose a PO for the selected supplier.');
        $summary=[];$exceptions=[];$groups=[];$balances=[];$seen=[];
        foreach ($data['entries'] as $index=>$entry) {
            $order=$orders->get($entry['poId']);
            abort_unless($order && (int)$order->supplierId===(int)$data['supplierId'],422,'All POs must belong to the same supplier.');
            abort_unless(in_array($order->status,[PurchaseWorkflow::WAITING,'Approved','Ordered','Partially Received','Completed','Received with Discrepancy']),422,'Only approved POs with outstanding goods can be received.');
            abort_unless((int)$order->receivingVersion===(int)$entry['version'],409,'PO '.$order->id.' changed. Reopen receiving and review its quantities.');
            $line=$lines->first(fn($l)=>$l->poId===$order->id && (int)$l->productId===(int)$entry['productId']);
            abort_unless($line,422,'An item does not belong to its original PO.');
            abort_if(ReceivingCarryover::reserved($line->id)>0 || (int)($line->carryoverQty ?? 0)>0,422,'Release old unreceived shortage links before receiving these goods under their original PO.');
            abort_unless(ReceivingCarryover::remaining($line)>0,422,'This item is already fully received.');
            if ($entry['type']==='current') abort_unless($order->id===$primary->id,422,'Current products must belong to the main PO.');
            else {
                abort_unless(DB::table('receiving_records')->where('poId',$order->id)->exists(),422,'Choose an item with a previous delivery.');
                abort_unless($order->created<=$primary->created,422,'Choose an earlier PO for outstanding items.');
            }
            $key=$order->id.'|'.$line->productId;
            abort_if(isset($seen[$key.'|'.$entry['type']]),422,'The same PO item and receiving type was added twice.');
            $seen[$key.'|'.$entry['type']]=true;
            $balance=$balances[$key] ?? self::obligation($line);
            $delivered=(int)$entry['delivered'];$damaged=(int)$entry['damaged'];$accepted=$delivered-$damaged;
            abort_if($delivered===0 || $damaged>$delivered,422,'Delivered quantity must be positive and damaged quantity cannot exceed it.');
            abort_if($damaged && (!trim($entry['damageReason'] ?? '') || empty($entry['disposition'])),422,'Choose a damage disposition and enter a reason.');
            $type=$entry['type'];
            if ($balance['unclassified'] && in_array($type,['missing','damaged'])) abort(422,'Older quantities are unclassified. Choose Previous outstanding item; do not guess a damage balance.');
            if ($type==='missing') abort_if($delivered>$balance['missing'],422,'Delivery exceeds the outstanding missing quantity.');
            if ($type==='damaged') abort_if($delivered>$balance['damaged'],422,'Delivery exceeds the damaged replacement quantity due.');
            if ($type==='outstanding') abort_if($delivered>$balance['total'],422,'Delivery exceeds the previous outstanding quantity.');
            abort_if($delivered>$balance['total'],422,'Delivery cannot exceed the outstanding quantity.');
            $target=InventoryRules::product(($entry['substituteProductId'] ?? null) ?: $entry['productId']);
            abort_unless(strtolower($line->unit)===strtolower($target->stockUnit ?: $target->unit),422,'Replacement products must use the same stock unit.');
            $substitute=(int)$target->id!==(int)$line->productId;
            if ($substitute) {
                abort_unless(trim($entry['substitutionReason'] ?? ''),422,'Explain the substitution.');
                abort_if($accepted>$balance['total'],422,'A substitute must have equal-or-lower unit cost and cannot exceed the outstanding quantity.');
                $exceptions[]='Substitution: '.$line->name.' → '.$target->name.' ('.$order->id.')';
            }
            if ((int)round($entry['unitCost']*100)!==(int)round($line->unitCost*100)) $exceptions[]='Purchase-cost change: '.$order->id.' / '.$line->name;
            abort_unless(array_sum(array_column($entry['lots'],'quantity'))===$accepted,422,'Lot quantities must equal accepted quantity for '.$target->name.'.');
            $perishable=DB::table('categories')->where('name',$target->category)->value('classification')==='Perishable';
            foreach ($entry['lots'] as $lot) {
                abort_if($perishable && empty($lot['expiryDate']),422,'Expiration date is required for '.$target->name.'.');
                abort_if(!empty($lot['expiryDate']) && $lot['expiryDate']<=now()->toDateString(),422,'Expired stock cannot be accepted.');
                abort_if(!empty($lot['manufacturedDate']) && $lot['manufacturedDate']>$data['deliveryDate'],422,'Manufacturing date cannot follow delivery.');
            }
            $damageAccepted=$type==='damaged'?$accepted:max(0,$accepted-$balance['missing']);
            $newDamage=$type==='damaged'?0:min($damaged,max(0,$balance['missing']-$accepted));
            $afterTotal=max(0,$balance['total']-$accepted);
            $afterDamage=min($afterTotal,max(0,$balance['damaged']-$damageAccepted+$newDamage));
            $balances[$key]=['total'=>$afterTotal,'damaged'=>$afterDamage,'missing'=>$afterTotal-$afterDamage,'unclassified'=>false];
            $entryDue=$type==='missing'?$balance['missing']:($type==='damaged'?$balance['damaged']:$balance['total']);
            $summary[]=$entry+['name'=>$target->name,'originalName'=>$line->name,'sku'=>$target->sku ?? $target->barcode,'unit'=>$line->unit,'accepted'=>$accepted,'undelivered'=>max(0,$entryDue-$delivered),'remaining'=>$afterTotal,'damageDue'=>$afterDamage,'missingDue'=>$afterTotal-$afterDamage,'orderLine'=>$line->id,'targetProductId'=>$target->id];
            if (isset($groups[$key])) abort_unless($groups[$key]['targetProductId']===$target->id && (float)$groups[$key]['unitCost']===(float)$entry['unitCost'],422,'Use one received product and unit cost for each original PO line.');
            $group=$groups[$key] ?? ['poId'=>$order->id,'version'=>$entry['version'],'productId'=>$line->productId,'targetProductId'=>$target->id,'substituteProductId'=>$substitute?$target->id:null,'substitutionReason'=>$type==='damaged'?'damaged':'missing','unitCost'=>$entry['unitCost'],'qty'=>0,'damagedQty'=>0,'damageReason'=>'','lots'=>[]];
            $group['qty']+=$accepted;$group['damagedQty']+=$damaged;$group['damageReason'].=($entry['damageReason'] ?? '').' ';$group['lots']=array_merge($group['lots'],$entry['lots']);
            PurchaseBudget::checkLine($line, InventoryRules::lineTotal($group['qty'],$group['unitCost']));
            $groups[$key]=$group;
        }
        foreach ($orders as $order) {
            $value=collect($groups)->where('poId',$order->id)->sum(fn($g)=>InventoryRules::lineTotal($g['qty'],$g['unitCost']));
            PurchaseBudget::check($order,round($order->deliveredValue+$value,2));
        }
        if ($exceptions) abort_unless(trim($data['notes'] ?? ''),422,'Add receiving notes explaining the exceptions for approval.');
        return compact('summary','exceptions','groups','orders','balances');
    }

    public function submit(Request $request)
    {
        InventoryRules::authorize($request,['manager','superadmin','super_admin']);
        $data=$this->data($request);$hash=hash('sha256',json_encode($data));
        return DB::transaction(function () use ($request,$data,$hash) {
            InventoryRules::lockActor($request);
            DB::table('inventory_sequences')->where('name','purchase_orders')->lockForUpdate()->first();
            $existing=DB::table('stock_receipts')->where('requestKey',$data['requestKey'])->first();
            if ($existing) {
                abort_unless($existing->payloadHash===$hash,409,'This receipt key was already used. Reopen the form for another delivery.');
                return response()->json(['id'=>$existing->id,'status'=>$existing->status,'message'=>'Stock receipt already saved.']);
            }
            $checked=$this->inspect($data);$id='SR-'.now()->format('Y').'-'.Str::ulid();
            $status=$checked['exceptions']?'Pending Approval':'Ready';
            DB::table('stock_receipts')->insert(['id'=>$id,'requestKey'=>$data['requestKey'],'payloadHash'=>$hash,'supplierId'=>$data['supplierId'],'deliveryDate'=>$data['deliveryDate'],'status'=>$status,'payload'=>json_encode($data),'summary'=>json_encode($checked['summary']),'exceptions'=>json_encode($checked['exceptions']),'requestedBy'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]);
            if ($status==='Ready') $this->postChecked($request,$id,$data,$checked,false);
            else WorkflowNotifications::send(WorkflowNotifications::reviewers(),$request->user(),'Stock receipt approval','Review stock receipt '.$id.'. No inventory has been posted.','purchase',$data['primaryPo']);
            InventoryRules::audit($request->user()->name,'Submitted stock receipt',$id,null,$status);
            return response()->json(['id'=>$id,'status'=>$status==='Ready'?'Posted':$status,'message'=>$status==='Ready'?'Stock receipt posted. Accepted quantities added to inventory.':'Stock receipt saved for admin approval. No inventory added.'],201);
        },3);
    }

    public function review(Request $request,string $id)
    {
        InventoryRules::authorize($request,['admin','superadmin','super_admin']);
        $data=$request->validate(['action'=>['required','in:Approved,Declined'],'note'=>['required','string','max:2000']]);
        abort_unless(trim($data['note']),422,'Enter a review note.');
        return DB::transaction(function () use ($request,$id,$data) {
            InventoryRules::lockActor($request);
            DB::table('inventory_sequences')->where('name','purchase_orders')->lockForUpdate()->first();
            $record=DB::table('stock_receipts')->where('id',$id)->lockForUpdate()->first();abort_unless($record,404);
            abort_if($record->requestedBy==$request->user()->id,403,'Another admin must review this receipt.');
            abort_unless($record->status==='Pending Approval',409,'This receipt has already been reviewed.');
            if ($data['action']==='Approved') $this->inspect(json_decode($record->payload,true));
            DB::table('stock_receipts')->where('id',$id)->update(['status'=>$data['action'],'reviewedBy'=>$request->user()->id,'reviewNote'=>$data['note'],'reviewed_at'=>now(),'updated_at'=>now()]);
            InventoryRules::audit($request->user()->name,'Reviewed stock receipt',$id,'Pending Approval',$data['action'].': '.$data['note']);
            return response()->json(['message'=>'Receipt '.strtolower($data['action']).'. Approval does not post inventory.']);
        },3);
    }

    public function post(Request $request,string $id)
    {
        InventoryRules::authorize($request,['manager','superadmin','super_admin']);
        return DB::transaction(function () use ($request,$id) {
            InventoryRules::lockActor($request);
            DB::table('inventory_sequences')->where('name','purchase_orders')->lockForUpdate()->first();
            $record=DB::table('stock_receipts')->where('id',$id)->lockForUpdate()->first();abort_unless($record,404);
            if ($record->status==='Posted') return response()->json(['id'=>$id,'message'=>'Receipt already posted; stock was not added again.']);
            abort_unless($record->status==='Approved',422,'This receipt requires approval before posting.');
            $data=json_decode($record->payload,true);$checked=$this->inspect($data);
            $this->postChecked($request,$id,$data,$checked,true);
            return response()->json(['id'=>$id,'message'=>'Approved stock receipt posted.']);
        },3);
    }

    private function postChecked(Request $request,string $id,array $data,array $checked,bool $approved): void
    {
        $lotMap=[];$receipts=[];
        foreach ($checked['orders'] as $order) {
            $groups=collect($checked['groups'])->where('poId',$order->id);
            if ($groups->isEmpty()) continue;
            $value=$groups->sum(fn($g)=>InventoryRules::lineTotal($g['qty'],$g['unitCost']));
            PurchaseBudget::check($order,round($order->deliveredValue+$value,2));
            $lotsByProduct=[];$lines=[];
            foreach ($groups as $group) {
                $lots=[];
                foreach ($group['lots'] as $lot) {
                    // Unknown manufacturer lots are never merged just because dates match.
                    $key=!empty($lot['supplierLot'])?json_encode([$group['targetProductId'],trim($lot['supplierLot']),$lot['expiryDate'] ?? null,$lot['manufacturedDate'] ?? null]):(string)Str::uuid();
                    if (!isset($lotMap[$key])) {
                        $lotId='LOT-'.Str::ulid();$lotMap[$key]=$lotId;
                        DB::table('physical_inventory_lots')->insert(['id'=>$lotId,'productId'=>$group['targetProductId'],'supplierId'=>$data['supplierId'],'supplierLot'=>trim($lot['supplierLot'] ?? '') ?: null,'expiryDate'=>$lot['expiryDate'] ?? null,'manufacturedDate'=>$lot['manufacturedDate'] ?? null,'stockReceipt'=>$id,'created_at'=>now()]);
                    }
                    $lots[]=$lot+['batchNumber'=>$lotMap[$key],'physicalLotId'=>$lotMap[$key]];
                }
                $lotsByProduct[$group['productId']]=$lots;
                $lines[]=array_diff_key($group,array_flip(['lots','poId','version','targetProductId']));
            }
            // Persist classified replacement balances inside the same transaction before
            // the child receipt snapshots every PO line.
            foreach ($checked['summary'] as $entry) {
                if ($entry['poId']===$order->id) DB::table('purchase_order_lines')->where('id',$entry['orderLine'])->update(['damageOutstanding'=>$entry['damageDue']]);
            }
            $child=Request::create('/','POST',['idempotencyKey'=>(string)Str::uuid(),'version'=>$order->receivingVersion,'receivedDate'=>$data['deliveryDate'],'supplierDocument'=>$data['supplierReference'] ?? null,'notes'=>$data['notes'] ?? '', 'lines'=>$lines]);
            $child->setUserResolver(fn()=>$request->user());
            $receipt=app(PurchasingController::class)->receive($child,$order->id,$id,$lotsByProduct)->getData(true);
            $receipts[]=['poId'=>$order->id,'receiptId'=>$receipt['id']];
        }
        foreach ($checked['summary'] as $entry) {
            DB::table('receiving_obligation_events')->insert(['stockReceipt'=>$id,'orderLine'=>$entry['orderLine'],'type'=>$entry['type'],'delivered'=>$entry['delivered'],'accepted'=>$entry['accepted'],'damaged'=>$entry['damaged'],'missingAfter'=>$entry['missingDue'],'damageAfter'=>$entry['damageDue'],'disposition'=>$entry['disposition'] ?? null,'reason'=>$entry['damageReason'] ?? null,'created_at'=>now()]);
            DB::table('purchase_order_lines')->where('id',$entry['orderLine'])->update(['damageOutstanding'=>$entry['damageDue']]);
        }
        DB::table('stock_receipts')->where('id',$id)->update(['status'=>'Posted','receipts'=>json_encode($receipts),'posted_at'=>now(),'updated_at'=>now()]);
        InventoryRules::audit($request->user()->name,'Posted stock receipt',$id,null,json_encode($receipts));
    }

    public function report(Request $request,string $id)
    {
        InventoryRules::authorize($request);
        $receipt=DB::table('stock_receipts')->where('id',$id)->first();abort_unless($receipt,404);
        $summary=json_decode($receipt->summary,true);$payload=json_decode($receipt->payload,true);
        $supplier=DB::table('suppliers')->where('id',$receipt->supplierId)->value('name');
        $receiver=DB::table('users')->where('id',$receipt->requestedBy)->value('name');
        $lots=DB::table('physical_inventory_lots')->where('stockReceipt',$id)->get();
        foreach ($lots as $lot) $lot->allocations=DB::table('receiving_record_lines as l')->join('receiving_records as r','r.id','=','l.receivingId')->where('l.physicalLotId',$lot->id)->select('r.poId','l.deliveredQty','l.unitCost')->get();
        return response()->view('stock-receipt',compact('receipt','summary','payload','supplier','receiver','lots'))->header('Cache-Control','private, no-store');
    }
}
