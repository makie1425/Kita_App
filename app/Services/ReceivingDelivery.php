<?php

namespace App\Services;

use App\Http\Controllers\PurchasingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReceivingDelivery
{
    public function store(Request $request)
    {
        InventoryRules::authorize($request, ['manager','superadmin','super_admin']);
        $data=$request->validate([
            'requestKey'=>['required','uuid'], 'supplierId'=>['required','integer'],
            'receivedDate'=>['required','date_format:Y-m-d','before_or_equal:today'],
            'orders'=>['required','array','min:1','max:50'],
            'orders.*.poId'=>['required','string','distinct'],
            'orders.*.version'=>['required','integer','min:0'],
            'orders.*.paymentDueDate'=>['nullable','date_format:Y-m-d','after_or_equal:receivedDate'],
            'orders.*.supplierDocument'=>['nullable','string','max:100'],
            'orders.*.notes'=>['nullable','string','max:2000'],
            'orders.*.lines'=>['required','array','min:1','max:500'],
        ]);
        $data['supplierId']=(int)$data['supplierId'];
        $data['orders']=collect($data['orders'])->sortBy('poId')->values()->all();
        $hash=hash('sha256',json_encode($data));
        return DB::transaction(function () use ($request,$data,$hash) {
            InventoryRules::lockActor($request);
            // Supplier lock serializes delivery retries, including requests from different managers.
            $supplier=DB::table('suppliers')->where('id',$data['supplierId'])->lockForUpdate()->first();
            abort_unless($supplier,422,'Select a valid supplier.');
            $existing=DB::table('receiving_deliveries')->where('requestKey',$data['requestKey'])->first();
            if ($existing) {
                abort_unless($existing->payloadHash===$hash,409,'This delivery key was already used for different items.');
                return response()->json(['message'=>'Delivery already recorded; stock was not added again.','batchNumber'=>$existing->batchNumber,'receipts'=>json_decode($existing->receipts,true)]);
            }
            $ids=array_column($data['orders'],'poId');
            $sourceIds=DB::table('receiving_carryovers')->whereIn('targetPo',$ids)->pluck('sourcePo')->all();
            $orders=DB::table('purchase_orders')->whereIn('id',array_unique([...$ids,...$sourceIds]))->orderBy('id')->lockForUpdate()->get();
            foreach ($ids as $id) {
                $order=$orders->firstWhere('id',$id);
                abort_unless($order && (int)$order->supplierId===$data['supplierId'],422,'All selected POs must belong to the delivery supplier.');
            }
            // One product has one current selling price, even when received under several POs.
            $prices=[];
            foreach ($data['orders'] as $order) foreach ($order['lines'] as $line) {
                $product=$line['substituteProductId'] ?? $line['productId'] ?? null;
                if (isset($line['sellingPrice']) && is_numeric($line['sellingPrice'])) {
                    $price=(int)round((float)$line['sellingPrice']*100);
                    abort_if(isset($prices[$product]) && $prices[$product]!==$price,422,'Use the same selling price for a product across this delivery.');
                    $prices[$product]=$price;
                }
            }
            $batch=ReceivingNumber::next('BATCH'); $receipts=[]; $receivedAt=now();
            foreach ($data['orders'] as $order) {
                $child=Request::create('/', 'POST', [
                    'idempotencyKey'=>(string)Str::uuid(), 'version'=>$order['version'],
                    'receivedDate'=>$data['receivedDate'], 'paymentDueDate'=>$order['paymentDueDate'] ?? null, 'supplierDocument'=>$order['supplierDocument'] ?? null, 'notes'=>$order['notes'] ?? '', 'lines'=>$order['lines'],
                ]);
                $child->setUserResolver(fn ()=>$request->user());
                $result=app(PurchasingController::class)->receive($child,$order['poId'],$batch)->getData(true);
                DB::table('receiving_records')->where('id',$result['id'])->update(['received_at'=>$receivedAt]);
                $receipts[]=['poId'=>$order['poId'],'receiptId'=>$result['id'],'status'=>$result['status']];
            }
            DB::table('receiving_deliveries')->insert(['batchNumber'=>$batch,'requestKey'=>$data['requestKey'],'payloadHash'=>$hash,'supplierId'=>$data['supplierId'],'receivedDate'=>$data['receivedDate'],'receivedBy'=>$request->user()->id,'receipts'=>json_encode($receipts),'created_at'=>now()]);
            return response()->json(['message'=>'Delivery recorded. All included POs share batch '.$batch.'.','batchNumber'=>$batch,'receipts'=>$receipts],201);
        },3);
    }
}
