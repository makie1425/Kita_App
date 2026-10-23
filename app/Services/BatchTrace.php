<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
class BatchTrace
{
    // Links are reference-level provenance, not an inferred per-movement quantity split.
    public static function movements($rows)
    {
        $references=$rows->pluck('referenceId')->filter()->unique();
        $receipts=DB::table('inventory_batches')->whereIn('receiptId',$references)->get()->groupBy(fn($b)=>$b->productId.'|'.$b->receiptId);
        $allocations=DB::table('inventory_allocations as a')->join('inventory_batches as b','b.id','=','a.batchId')->whereIn('a.referenceId',$references)->get(['a.productId','a.referenceId','a.referenceType','b.id','b.batchNumber'])->groupBy(fn($b)=>$b->productId.'|'.$b->referenceId);
        foreach($rows as $row) {
            $key=$row->productId.'|'.$row->referenceId;
            $batches=$row->referenceType==='purchase_receiving'?$receipts->get($key,collect()):$allocations->get($key,collect())->filter(fn($a)=>$a->referenceType===$row->referenceType || (in_array($row->referenceType,['refund','void','exchange','payment_release']) && in_array($a->referenceType,['checkout','payment_reservation','exchange_replacement'])));
            $row->batchNumbers=$batches->map(fn($b)=>$b->batchNumber?:'Batch #'.$b->id)->unique()->implode(', ') ?: 'Not recorded';
        }
        return $rows;
    }
}
