<?php
namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Adopt only the complete, deterministic legacy grocery dataset; never infer ownership from counts. */
class LegacyGroceryCheckpoint
{
    public static function adopt(int $workspace, User $manager): void
    {
        DB::transaction(function () use ($workspace, $manager) {
            DB::table('workspaces')->where('id', $workspace)->lockForUpdate()->first();
            $existing=DB::table('grocery_imports')->where('workspace_id',$workspace)->first();
            if ($existing) {
                if ((int)$existing->manager_id !== (int)$manager->id || !$existing->completed) throw new \RuntimeException('Existing grocery checkpoint is incomplete or has a different owner.');
                return;
            }
            if (WorkspaceRead::forUser($manager) !== $workspace) throw new \RuntimeException('Legacy import owner is not assigned to this workspace.');
            $prefix='GR'.$workspace;
            $barcodes=[];$sales=[];$orders=[];$receipts=[];
            for($i=0;$i<50;$i++) {
                $barcodes[]=$prefix.'-'.str_pad((string)$i,4,'0',STR_PAD_LEFT);
                foreach([2024,2025,2026] as $year){$sales[]=$prefix.'-'.$year.'-'.$i;$orders[]=$prefix.'-PO-'.$year.'-'.$i;$receipts[]=$prefix.'-RC-'.$year.'-'.$i;}
            }
            $scoped=fn($table)=>DB::table($table)->where('workspace_id',$workspace);
            $products=$scoped('products')->whereIn('barcode',$barcodes)->orderBy('barcode')->get();
            $valid=$products->count()===50 && $products->pluck('barcode')->unique()->count()===50
                && $scoped('transactions')->whereIn('uuid',$sales)->where('cashierId',$manager->id)->where('cashierRole',$manager->role)->count()===150
                && $scoped('purchase_orders')->whereIn('id',$orders)->count()===150
                && $scoped('receiving_records')->whereIn('id',$receipts)->count()===150
                && $scoped('purchase_orders')->where('id',$prefix.'-PO-CANCEL')->where('status','Cancelled')->exists();
            $suppliers=$scoped('suppliers')->whereIn('id',$products->pluck('supplierId'))->pluck('id')->all();
            $links=$scoped('supplier_products')->whereIn('productId',$products->pluck('id'))->get()->keyBy('productId');
            $batches=$scoped('inventory_batches')->whereIn('receiptId',$receipts)->get()->keyBy('receiptId');
            foreach($products as $i=>$p){
                $valid=$valid && in_array($p->supplierId,$suppliers) && isset($links[$p->id]) && (int)$links[$p->id]->supplierId===(int)$p->supplierId;
                foreach([2024,2025,2026] as $year){$batch=$batches->get($prefix.'-RC-'.$year.'-'.$i);$valid=$valid && $batch && (int)$batch->productId===(int)$p->id && (int)$batch->supplierId===(int)$p->supplierId;}
            }
            if(!$valid) throw new \RuntimeException('Legacy grocery verification failed: expected owned products, suppliers, sales, orders or batches are missing. No checkpoint created.');
            $catalog=$products->map(fn($p)=>['id'=>$p->id,'name'=>$p->name,'supplier'=>$p->supplierId,'cost'=>$p->unitPrice])->all();
            DB::table('grocery_imports')->insert(['workspace_id'=>$workspace,'manager_id'=>$manager->id,'products'=>json_encode($catalog,JSON_THROW_ON_ERROR),'completed_steps'=>150,'completed'=>true,'created_at'=>now(),'updated_at'=>now()]);
        });
    }
}
