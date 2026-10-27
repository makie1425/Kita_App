<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchasingReports
{
    public const TYPES = ['purchase_orders', 'outstanding_orders', 'purchases', 'supplier_book', 'payables'];

    public static function data(Request $request): array
    {
        $type = $request->input('type');
        $titles = ['purchase_orders'=>'Purchase Order Progress', 'outstanding_orders'=>'Outstanding Deliveries', 'purchases'=>'Purchase Receipt Detail', 'supplier_book'=>'Supplier Book', 'payables'=>'Accounts Payable'];
        $title = $titles[$type];
        $moneyKeys = ['unitCost','amount','receivedValue','payments','credits','balance','debit','credit'];
        $numericKeys = array_merge($moneyKeys, ['orderedQty','deliveredQty','resolvedQty','outstandingQty','quantity']);
        $note = 'Only accepted receipts increase inventory and supplier payable. Orders and undelivered or damaged quantities do not create a payable.';
        $labels = ['Type'=>$title];
        $dateColumn = 'date';
        if (in_array($type, ['purchase_orders', 'outstanding_orders'])) {
            $incoming = DB::table('receiving_carryovers')->select('targetLine')->selectRaw('SUM(quantity-receivedQty-releasedQty) as incomingQty')->groupBy('targetLine');
            $query = DB::table('purchase_order_lines as l')->join('purchase_orders as po','po.id','=','l.poId')
                ->leftJoin('item_requests as req','req.id','=','po.itemRequestId')->leftJoin('products as p','p.id','=','l.productId')
                ->leftJoinSub($incoming, 'carry', 'carry.targetLine', '=', 'l.id')
                ->selectRaw('COALESCE(carry.incomingQty, 0) as incomingQty')->addSelect('l.*','po.created as date','po.supplierId','po.supplierName as supplier','po.status','po.expectedDeliveryDate','po.supplierSentDate','po.supplierSentVia','req.requestedBy as manager','p.brandId','p.subcategoryId');
            self::filterLines($query, $request, 'po.created', 'req.requestedBy');
            $rows = $query->get();
            foreach ($rows as $row) {
                $row->reportPo = $row->poId;
                $row->outstandingQty = max(0, ReceivingCarryover::remaining($row) - (int) $row->incomingQty);
                $row->remarks = $row->status === 'Cancelled' ? 'Outstanding quantities cancelled' : ($row->outstandingQty > 0 ? ($row->expectedDeliveryDate && $row->expectedDeliveryDate < now()->toDateString() ? 'Delivery overdue' : 'Awaiting remaining goods') : 'No outstanding quantity');
            }
            if ($type === 'outstanding_orders') $rows = $rows->filter(fn ($r) => $r->outstandingQty > 0 && !in_array($r->status, ['Cancelled','Closed']));
            $columns = ['PO Number'=>'reportPo','Order Date'=>'date','Supplier'=>'supplier','Requested By'=>'manager','Expected Delivery'=>'expectedDeliveryDate','Sent Date'=>'supplierSentDate','Sent Via'=>'supplierSentVia','Item Code'=>'productId','Item'=>'name','Stock Unit'=>'unit','Ordered Qty'=>'orderedQty','Accepted Here'=>'deliveredQty','Resolved on Linked PO'=>'resolvedQty','Outstanding Qty'=>'outstandingQty','Unit Cost (PHP)'=>'unitCost','Delivery Remarks'=>'remarks'];
            $note .= ' Date filters use order date. Outstanding quantities exclude incoming legacy carryover copies to avoid counting the same obligation twice.';
        } elseif ($type === 'purchases') {
            $query = DB::table('receiving_record_lines as l')->join('receiving_records as r','r.id','=','l.receivingId')->join('purchase_orders as po','po.id','=','r.poId')
                ->leftJoin('products as p','p.id','=','l.productId')->select(['l.*','r.date','r.poId as reportPo','r.supplierName as supplier','po.supplierId','r.receivedBy as manager','r.supplierDocument','r.inspection','r.outcome','p.brandId','p.subcategoryId']);
            self::filterLines($query, $request, 'r.date', 'r.receivedBy');
            $rows = $query->get();
            foreach ($rows as $row) { $row->amount = round($row->deliveredQty * ($row->unitCost ?? 0), 2); $row->remarks = ReceivingRemarks::forReceipt($row); }
            $columns = ['Receipt Date'=>'date','PO Number'=>'reportPo','Receipt ID'=>'receivingId','Supplier Document'=>'supplierDocument','Supplier'=>'supplier','Received By'=>'manager','Item Code'=>'productId','Item'=>'name','Stock Unit'=>'unit','Accepted Qty'=>'deliveredQty','Unit Cost (PHP)'=>'unitCost','Received Value (PHP)'=>'amount','Batch'=>'batchNumber','Inspection Remarks'=>'remarks'];
            $note .= ' Date filters use receipt date. Each delivery is listed separately, including later receipts on the same PO.';
        } else {
            $receipts = SupplierAccounts::receipts()->get()->map(fn ($r)=>SupplierAccounts::describe($r));
            if ($type === 'payables') {
                $rows = $receipts->map(function ($r) { $r->reportPo=$r->poId; $r->supplier=$r->supplierName; $r->manager=$r->receivedBy; return $r; });
                $columns = ['Receipt Date'=>'date','PO Number'=>'reportPo','Receipt ID'=>'id','Supplier'=>'supplier','Supplier Document'=>'supplierDocument','Received By'=>'manager','Due Date'=>'paymentDueDate','Accepted Value (PHP)'=>'receivedValue','Payments (PHP)'=>'payments','Credits (PHP)'=>'credits','Unpaid Balance (PHP)'=>'balance','Account Remark'=>'accountRemark'];
                $note .= ' All receipt balances are shown, including settled receipts. Date filters select receipts; payments and credits include all posted dates. Missing due dates are not treated as overdue. Legacy payments or unknown costs require reconciliation and are excluded from unpaid totals.';
            } else {
                $rows = collect();
                $settlements = DB::table('supplier_settlements')->orderBy('id')->get()->groupBy('receiptId');
                foreach ($receipts as $r) {
                    $base = ['reportPo'=>$r->poId,'receiptId'=>$r->id,'supplierId'=>$r->supplierId,'supplier'=>$r->supplierName];
                    $rows->push((object) ($base + ['date'=>$r->date,'kind'=>'Purchase receipt','reference'=>$r->supplierDocument ?: $r->id,'manager'=>$r->receivedBy,'debit'=>(float)$r->receivedValue,'credit'=>0,'remarks'=>$r->accountRemark === 'Review legacy costs / payments' ? $r->accountRemark : $r->remarks]));
                    foreach ($settlements->get($r->id, collect()) as $entry) $rows->push((object) ($base + ['date'=>$entry->date,'kind'=>$entry->kind,'reference'=>$entry->reference,'manager'=>$entry->actor,'debit'=>0,'credit'=>$entry->voidedAt ? 0 : (float)$entry->amount,'remarks'=>$entry->voidedAt ? 'Voided: '.$entry->voidReason : $entry->notes]));
                }
                $columns = ['Entry Date'=>'date','Supplier'=>'supplier','Type'=>'kind','PO Number'=>'reportPo','Receipt ID'=>'receiptId','Document / Reference'=>'reference','Recorded By'=>'manager','Purchase Debit (PHP)'=>'debit','Payment / Credit (PHP)'=>'credit','Remarks'=>'remarks'];
                $note .= ' Date filters select entry dates. Period debits minus credits are period activity, not the closing balance; use Accounts Payable for current unpaid balances. Voided entries remain visible with zero effect.';
            }
        }
        foreach (['supplierId','productId','category','brandId','subcategoryId','manager'] as $key) {
            if ($request->filled($key)) $rows = $rows->filter(fn ($r)=>(string)($r->$key ?? '') === (string)$request->input($key));
        }
        foreach (['from','to'] as $key) if ($request->filled($key)) { $value=$request->input($key); $rows=$rows->filter(fn ($r)=>$key==='from' ? $r->date >= $value : substr($r->date,0,10) <= $value); $labels[$key==='from'?'From':'To']=$value; }
        if ($request->filled('supplierId')) $labels['Supplier']=DB::table('suppliers')->where('id',$request->input('supplierId'))->value('name');
        if ($request->filled('manager')) $labels['Recorded / Requested By']=$request->input('manager');
        foreach (['category'=>'Category','productId'=>'Item Code','brandId'=>'Brand ID','subcategoryId'=>'Item Group ID'] as $key=>$label) if ($request->filled($key)) $labels[$label]=$request->input($key);
        $rows=$rows->sortByDesc('date')->values();
        $summary=['Records'=>number_format($rows->count())];
        if ($type==='purchases') $summary['Accepted purchases (PHP)']=number_format($rows->sum('amount'),2);
        if ($type==='payables') {
            $summary['Unpaid balance (PHP)']=number_format($rows->sum('balance'),2);
            $summary['Overdue balance (PHP)']=number_format($rows->where('accountRemark','Overdue')->sum('balance'),2);
            $summary['Receipts needing review']=number_format($rows->where('accountRemark','Review legacy costs / payments')->count());
        }
        if ($type==='supplier_book') { $summary['Period purchases (PHP)']=number_format($rows->sum('debit'),2); $summary['Period payments / credits (PHP)']=number_format($rows->sum('credit'),2); }
        $preparedBy=$request->user()->name;
        $generated=now()->timezone('Asia/Manila')->format('Y-m-d H:i:s').' Asia/Manila';
        return compact('title','columns','rows','note','labels','summary','moneyKeys','numericKeys','preparedBy','generated');
    }
    private static function filterLines($query, Request $request, string $date, string $manager): void
    {
        foreach (['supplierId'=>'po.supplierId','productId'=>'l.productId','category'=>'l.category','brandId'=>'p.brandId','subcategoryId'=>'p.subcategoryId','manager'=>$manager] as $key=>$column) {
            if ($request->filled($key)) $query->where($column, $request->input($key));
        }
        if ($request->filled('from')) $query->where($date, '>=', $request->input('from'));
        if ($request->filled('to')) $query->where($date, '<', \Carbon\CarbonImmutable::parse($request->input('to'))->addDay()->toDateString());
    }

}
