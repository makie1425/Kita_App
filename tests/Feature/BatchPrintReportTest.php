<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BatchPrintReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_separates_complete_and_incomplete_items_and_totals_split_lots(): void
    {
        $this->actingAs(User::factory()->create(['role'=>'manager']));
        DB::table('products')->insert([
            ['id'=>1,'name'=>'Coke','category'=>'Drinks','stockUnit'=>'Piece'],
            ['id'=>2,'name'=>'Coffee','category'=>'Drinks','stockUnit'=>'Piece'],
            ['id'=>3,'name'=>'Tea','category'=>'Drinks','stockUnit'=>'Piece'],
        ]);
        DB::table('purchase_orders')->insert(['id'=>'PO-PRINT']);
        DB::table('purchase_order_lines')->insert(['poId'=>'PO-PRINT','productId'=>3,'name'=>'Tea','category'=>'Drinks','unitCost'=>5,'orderedQty'=>4]);
        DB::table('receiving_records')->insert(['id'=>'RCV-PRINT','poId'=>'PO-PRINT','inspection'=>json_encode([
            ['productId'=>1,'name'=>'Coke','ordered'=>10,'received'=>10,'outstanding'=>0],
            ['productId'=>2,'name'=>'Coffee','ordered'=>10,'received'=>6,'outstanding'=>4],
            ['productId'=>3,'name'=>'Tea','unit'=>'Piece','ordered'=>4,'received'=>0,'outstanding'=>4],
        ])]);
        foreach ([[1,10,10],[2,2,20],[2,4,20]] as [$product,$qty,$cost]) {
            DB::table('inventory_batches')->insert(['productId'=>$product,'receiptId'=>'RCV-PRINT','batchNumber'=>'BATCH-PRINT','quantityReceived'=>$qty,'quantityRemaining'=>0,'unitCost'=>$cost,'receivedDate'=>'2026-10-27','source'=>'purchase_receiving','created_at'=>now()]);
        }
        $id=DB::table('inventory_batches')->min('id');
        $response=$this->get('/inventory/batches/'.$id.'/print?productId=1')->assertOk();
        $response->assertSeeInOrder(['Complete items','Coke','Table subtotal','Incomplete items','Coffee','Tea','Table subtotal','Subtotal of all tables','Deductions','Total cost']);
        $response->assertViewHas('subtotal',320)->assertViewHas('deductions',100)->assertViewHas('totalCost',220);
        $response->assertViewHas('tables',fn ($tables)=>$tables['Incomplete items']->count()===2 && $tables['Incomplete items'][0]->ordered===10);
        // Subsequent PO changes do not change the saved inspection's shortage.
        DB::table('purchase_order_lines')->where('poId','PO-PRINT')->update(['deliveredQty'=>4]);
        $this->get('/inventory/batches/'.$id.'/print')->assertOk()->assertViewHas('deductions',100);
    }
}

