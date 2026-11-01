<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportsArchiveTest extends TestCase
{
 use RefreshDatabase;
 protected function setUp(): void {
  parent::setUp();$this->actingAs(User::factory()->create(['role'=>'manager']));
  DB::table('categories')->insert(['name'=>'Drinks','status'=>'Active']);
  DB::table('suppliers')->insert(['id'=>1,'name'=>'Supplier','status'=>'Active']);
  DB::table('brands')->insert(['id'=>1,'name'=>'Brand']);
  DB::table('subcategories')->insert(['id'=>1,'name'=>'Soda','category'=>'Drinks']);
  DB::table('products')->insert(['id'=>1,'name'=>'Coke','category'=>'Drinks','brandId'=>1,'subcategoryId'=>1,'supplierId'=>1,'stock'=>0,'status'=>'Active']);
  DB::table('item_requests')->insert(['id'=>'REQ1','requestedBy'=>'Manager']);
  DB::table('purchase_orders')->insert(['id'=>'PO1','itemRequestId'=>'REQ1','supplierId'=>1,'supplierName'=>'Supplier','created'=>'2026-10-27','status'=>'Partially Received']);
  DB::table('purchase_order_lines')->insert(['poId'=>'PO1','productId'=>1,'category'=>'Drinks','name'=>'Coke','orderedQty'=>10,'deliveredQty'=>4,'unitCost'=>5]);
 }
 public function test_all_report_types_respond_and_batch_exports_filter():void {
  DB::table('inventory_batches')->insert(['productId'=>1,'supplierId'=>1,'batchNumber'=>'BATCH1','quantityReceived'=>4,'quantityRemaining'=>4,'unitCost'=>5,'receivedDate'=>'2026-10-27','source'=>'receiving','created_at'=>now()]);
  foreach(['sales','inventory','receipts','batches','movements','expiry','receiving','purchase_orders','outstanding_orders','purchases','supplier_book','payables'] as $type){
   $this->getJson('/reports/data?type='.$type)->assertOk()->assertJsonStructure(['rows','columns']);
  }
  $this->getJson('/reports/data?type=batches&batch=BATCH1&category=Drinks&supplierId=1&from=2026-10-27&to=2026-10-27')->assertOk()->assertJsonCount(1,'rows')->assertJsonPath('rows.0.batch','BATCH1');
  $this->getJson('/reports/data?type=batches&batch=missing')->assertOk()->assertJsonCount(0,'rows');
  $this->get('/reports/data?type=batches&batch=BATCH1&format=print')->assertOk()->assertSee('BATCH1');
  $csv=$this->get('/reports/data?type=batches&batch=BATCH1&format=csv')->assertOk()->streamedContent();$this->assertStringContainsString('BATCH1',$csv);
 }
 public function test_purchase_filters_and_query_count_do_not_grow_per_item():void {
  $url='/reports/data?type=outstanding_orders&category=Drinks&supplierId=1&brandId=1&subcategoryId=1&manager=Manager&from=2026-10-27&to=2026-10-27';
  $this->getJson($url)->assertOk()->assertJsonCount(1,'rows')->assertJsonPath('rows.0.outstandingQty',6);
  $this->getJson($url.'&productId=1')->assertOk()->assertJsonCount(1,'rows');
  $this->getJson('/reports/data?type=outstanding_orders&manager=Nobody')->assertOk()->assertJsonCount(0,'rows');
  $read=function(){DB::enableQueryLog();DB::flushQueryLog();$this->getJson('/reports/data?type=outstanding_orders')->assertOk();$n=count(DB::getQueryLog());DB::disableQueryLog();return $n;};
  $before=$read();for($i=0;$i<30;$i++)DB::table('purchase_order_lines')->insert(['poId'=>'PO1','productId'=>1,'orderedQty'=>10,'deliveredQty'=>4]);
  $this->assertSame($before,$read());
  $line=DB::table('purchase_order_lines')->min('id');
  DB::table('receiving_carryovers')->insert(['requestKey'=>'carry-test','sourcePo'=>'OLD','sourceLine'=>999,'targetPo'=>'PO1','targetLine'=>$line,'quantity'=>5,'receivedQty'=>1,'releasedQty'=>1,'actorId'=>1,'created_at'=>now()]);
  $rows=$this->getJson('/reports/data?type=outstanding_orders')->assertOk()->json('rows');
  $row=collect($rows)->firstWhere('id',$line);$this->assertEquals(3,$row['outstandingQty']);
 }
 public function test_purchase_order_receipt_arrangement_and_filters():void {
  foreach ([1,2] as $i) {
   DB::table('receiving_records')->insert(['id'=>'RCV'.$i,'poId'=>'PO1','date'=>'2026-10-2'.$i,'receivedBy'=>'Receiver','supplierName'=>'Supplier','deliveryBatch'=>'BATCH'.$i]);
   DB::table('receiving_record_lines')->insert(['receivingId'=>'RCV'.$i,'productId'=>1,'category'=>'Drinks','name'=>'Coke','unit'=>'Piece','deliveredQty'=>$i,'unitCost'=>55,'internalBatch'=>'OLD'.$i]);
  }
  $url='/reports/data?type=purchase_orders';
  $data=$this->getJson($url)->assertOk()->assertJsonCount(2,'rows')->assertJsonPath('rows.0.batch','BATCH2')->assertJsonPath('rows.0.receivingId','RCV2')->json();
  $this->assertEquals(110,$data['rows'][0]['amount']);
  $this->assertSame(['PO Number','Batch','Receipt ID','Item Code','Item','Stock Unit','Received Qty','Unit Cost (PHP)','Total Cost (PHP)','Supplier','Received By','Date Received','Inspection Remarks'],array_keys($data['columns']));
  $this->getJson($url.'&batch=BATCH1&manager=Receiver&supplierId=1&productId=1&category=Drinks&from=2026-10-21&to=2026-10-21')->assertOk()->assertJsonCount(1,'rows');
  $this->getJson($url.'&manager=Nobody')->assertOk()->assertJsonCount(0,'rows');
  $this->getJson($url.'&batch=missing')->assertOk()->assertJsonCount(0,'rows');
  $this->get($url.'&format=print')->assertOk()->assertSee('Date Received')->assertSee('BATCH2');
  $csv=$this->get($url.'&format=csv')->assertOk()->streamedContent();
  $this->assertStringContainsString('BATCH2',$csv);
 }
 public function test_archive_restore_delete_and_history_protection():void {
  $url='/api/inventory/workflows/archive';
  foreach(['products','suppliers','categories'] as $kind){$ids=[$kind==='categories'?'Drinks':'1'];
   $this->postJson($url,['kind'=>$kind,'ids'=>$ids])->assertOk();
   $this->assertDatabaseHas($kind,[$kind==='categories'?'name':'id'=>$ids[0],'status'=>'Inactive']);
   $this->postJson($url,['kind'=>$kind,'ids'=>$ids,'action'=>'delete'])->assertUnprocessable();
   $this->postJson($url,['kind'=>$kind,'ids'=>$ids,'action'=>'restore'])->assertOk();
   $this->assertDatabaseHas($kind,[$kind==='categories'?'name':'id'=>$ids[0],'status'=>'Active','archivedAt'=>null]);
  }
  DB::table('suppliers')->insert(['id'=>2,'name'=>'Unused','status'=>'Inactive']);
  $this->postJson($url,['kind'=>'suppliers','ids'=>['2'],'action'=>'delete'])->assertOk();$this->assertDatabaseMissing('suppliers',['id'=>2]);
  DB::table('products')->insert(['id'=>2,'name'=>'Unused item','stock'=>0,'status'=>'Inactive']);
  DB::table('categories')->insert(['name'=>'Unused category','status'=>'Inactive']);
  $this->postJson($url,['kind'=>'products','ids'=>['2'],'action'=>'delete'])->assertOk();$this->assertDatabaseMissing('products',['id'=>2]);
  $this->postJson($url,['kind'=>'categories','ids'=>['Unused category'],'action'=>'delete'])->assertOk();$this->assertDatabaseMissing('categories',['name'=>'Unused category']);
  $this->actingAs(User::factory()->create(['role'=>'cashier']));
  $this->postJson($url,['kind'=>'products','ids'=>['1'],'action'=>'restore'])->assertForbidden();
 }
}
