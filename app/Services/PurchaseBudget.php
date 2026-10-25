<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseBudget
{
    public static function additional(string $id): float
    {
        return (float) DB::table('purchase_budget_requests')->where('poId', $id)->where('status', 'Approved')->sum('amount');
    }

    public static function limit(object $order): float
    {
        return round((float) $order->orderedValue, 2);
    }

    public static function lineRemaining(object $line): float
    {
        $spent = DB::table('receiving_record_lines as l')->join('receiving_records as r','r.id','=','l.receivingId')
            ->where('r.poId',$line->poId)->whereRaw('COALESCE(l.originalProductId,l.productId) = ?',[$line->productId])
            ->selectRaw('COALESCE(SUM(l.deliveredQty * l.unitCost),0) as spent')->value('spent');
        return max(0, round($line->orderedQty * $line->unitCost - (float)$spent, 2));
    }

    public static function checkLine(object $line, float $value): void
    {
        $remaining=self::lineRemaining($line);
        abort_if((int)round($value*100)>(int)round($remaining*100),422,
            'Cannot receive: total cost exceeds the remaining approved value of PHP '.number_format($remaining,2,'.','').'. Only equal-or-lower-value replacements are allowed.');
    }

    public static function check(object $order, float $delivered): void
    {
        abort_if((int) round($delivered * 100) > (int) round(self::limit($order) * 100), 422,
            'This receipt exceeds the remaining approved receiving balance for '.$order->id.'. Only equal-or-lower-value receiving is allowed; this limit cannot be increased.');
    }

    public function request(Request $request, string $id)
    {
        InventoryRules::authorize($request, ['manager', 'superadmin', 'super_admin']);
        abort(422, 'Additional receiving amounts are disabled. Receiving must remain within the outstanding value.');
        $data=$request->validate(['requestKey'=>['required','uuid'], 'version'=>['required','integer','min:0'], 'amount'=>['required','numeric','decimal:0,2','min:0.01','max:99999999.99'], 'reason'=>['required','string','max:2000']]);
        abort_unless(trim($data['reason']),422,'Explain the price increase.');
        return DB::transaction(function () use ($request,$id,$data) {
            InventoryRules::lockActor($request);
            $order=DB::table('purchase_orders')->where('id',$id)->lockForUpdate()->first();
            abort_unless($order,404);
            $existing=DB::table('purchase_budget_requests')->where('requestKey',$data['requestKey'])->first();
            if ($existing) {
                abort_unless($existing->poId===$id && (float)$existing->amount===(float)$data['amount'] && $existing->reason===$data['reason'] && $existing->version==$data['version'],409,'This request key was already used.');
                return response()->json(['message'=>'Additional amount already requested.']);
            }
            abort_unless(in_array($order->status,[PurchaseWorkflow::WAITING,'Partially Received','Approved','Ordered','Completed','Received with Discrepancy']),409,'This PO is not open for receiving.');
            abort_unless($order->receivingVersion===$data['version'] || (int)$order->receivingVersion===$data['version'],409,'Refresh this PO before requesting an increase.');
            abort_if(DB::table('purchase_budget_requests')->where('poId',$id)->where('version',$data['version'])->where('status','Pending')->exists(),409,'An increase for this PO is already awaiting approval.');
            DB::table('purchase_budget_requests')->insert($data+['poId'=>$id,'requestedBy'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]);
            InventoryRules::audit($request->user()->name,'Requested receiving budget increase',$id,null,json_encode($data));
            WorkflowNotifications::send(WorkflowNotifications::reviewers(),$request->user(),'PO budget approval','Review additional receiving amount for '.$id.': '.$data['amount'].'.','purchase',$id);
            return response()->json(['message'=>'Additional amount sent for admin approval.'],201);
        },3);
    }

    public function review(Request $request, int $id)
    {
        InventoryRules::authorize($request,['admin','superadmin','super_admin']);
        abort_if($request->input('action')==='Approved',422,'The outstanding receiving limit cannot be increased.');
        $data=$request->validate(['action'=>['required','in:Approved,Declined']]);
        return DB::transaction(function () use ($request,$id,$data) {
            InventoryRules::lockActor($request);
            $entry=DB::table('purchase_budget_requests')->where('id',$id)->first(); abort_unless($entry,404);
            $order=DB::table('purchase_orders')->where('id',$entry->poId)->lockForUpdate()->first(); abort_unless($order,404);
            $entry=DB::table('purchase_budget_requests')->where('id',$id)->lockForUpdate()->first();
            abort_if($entry->requestedBy==$request->user()->id,403,'Another approver must review this request.');
            if ($entry->status!== 'Pending') {
                abort_unless($entry->status===$data['action'],409,'This request has already been reviewed.');
                return response()->json(['message'=>'Request already reviewed.']);
            }
            if ($data['action']==='Approved') {
                abort_unless((int)$entry->version===(int)$order->receivingVersion && in_array($order->status,[PurchaseWorkflow::WAITING,'Partially Received','Approved','Ordered','Completed','Received with Discrepancy']),409,'The PO changed. Decline this request and ask for a new amount.');
                abort_if(self::limit($order)+(float)$entry->amount>9999999999.99,422,'Approved amount exceeds the supported limit.');
            }
            DB::table('purchase_budget_requests')->where('id',$id)->update(['status'=>$data['action'],'reviewedBy'=>$request->user()->id,'reviewed_at'=>now(),'updated_at'=>now()]);
            if ($data['action']==='Approved') {
                DB::table('purchase_orders')->where('id',$order->id)->update(['outstandingValue'=>max(0,self::limit($order)-(float)$order->deliveredValue),'receivingVersion'=>$order->receivingVersion+1]);
            }
            InventoryRules::audit($request->user()->name,'Reviewed receiving budget increase',$order->id,null,json_encode(['request'=>$id,'amount'=>$entry->amount,'action'=>$data['action']]));
            return response()->json(['message'=>'Additional amount '.strtolower($data['action']).'.']);
        },3);
    }
}
