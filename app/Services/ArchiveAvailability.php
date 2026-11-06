<?php
namespace App\Services;

class ArchiveAvailability
{
    public static function check(string $kind, $records): void
    {
        $ids=$records->pluck($kind==='categories'?'name':'id')->all();
        $products=$kind==='products'?$ids:($kind==='categories'?WorkspaceRead::table('products')->whereIn('category',$ids)->pluck('id')->all():[]);
        if ($products) {
            abort_if(WorkspaceRead::table('inventory_allocations as a')->joinSub(WorkspaceRead::table('transactions'),'t','t.uuid','=','a.referenceId')->whereIn('a.productId',$products)->where('a.referenceType','payment_reservation')->where('t.status','Pending Payment')->exists(),409,'Resolve pending payments before archiving these products.');
            abort_if(WorkspaceRead::table('inventory_count_lines as l')->joinSub(WorkspaceRead::table('inventory_counts'),'c','c.id','=','l.countId')->whereIn('l.productId',$products)->where('c.status','Open')->exists(),409,'Complete or cancel the open stock count before archiving these products.');
            abort_if(WorkspaceRead::table('adjustments')->whereIn('productId',$products)->where('status','Pending Admin Approval')->exists(),409,'Resolve pending stock adjustments before archiving these products.');
        }
        $orders=WorkspaceRead::table('purchase_order_lines as l')->joinSub(WorkspaceRead::table('purchase_orders'),'po','po.id','=','l.poId')
            ->whereNotIn('po.status',['Cancelled','Closed','Declined','Rejected'])
            ->whereRaw('l.orderedQty > l.deliveredQty + COALESCE(l.resolvedQty,0)');
        if ($kind==='suppliers') $orders->whereIn('po.supplierId',$ids); else $orders->whereIn('l.productId',$products);
        abort_if($orders->exists(),409,'Resolve outstanding purchase deliveries or supplier replacements before archiving.');
        abort_if(SupplierAccounts::balances()->contains(fn ($row)=>$row->outstandingQty>0 && ($kind==='suppliers'?in_array($row->supplierId,$ids):in_array($row->productId,$products))),409,'Resolve historical supplier balances before archiving.');
        $requests=WorkspaceRead::table('item_request_lines as l')->joinSub(WorkspaceRead::table('item_requests'),'r','r.id','=','l.requestId')->whereIn('r.status',['Pending','Pending Approval','Proceed to Purchase']);
        if ($kind==='suppliers') $requests->whereIn('l.supplierId',$ids); else $requests->whereIn('l.productId',$products);
        abort_if($requests->exists(),409,'Resolve pending purchase requests before archiving.');
        foreach (WorkspaceRead::table('stock_receipts')->whereIn('status',['Pending Approval','Approved'])->get() as $receipt) {
            if ($kind==='suppliers' && in_array($receipt->supplierId,$ids)) abort(409,'Resolve pending stock receipts before archiving this supplier.');
            $payload=json_decode($receipt->payload,true) ?: [];
            foreach ($payload['entries'] ?? [] as $entry) {
                if (in_array($entry['productId'] ?? null,$products) || in_array($entry['substituteProductId'] ?? null,$products)) abort(409,'Resolve pending stock receipts before archiving these products.');
            }
        }
    }
}
