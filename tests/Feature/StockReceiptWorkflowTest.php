<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
class StockReceiptWorkflowTest extends TestCase {
 use RefreshDatabase;
 private $manager; private $admin; private $product; private $supplier;
 protected function setUp():void {
  parent::setUp();
  $this->manager=User::factory()->create(['role'=>'manager']);$this->admin=User::factory()->create(['role'=>'admin']);
  $this->actingAs($this->manager);
  $this->supplier=$this->postJson('/api/suppliers',['name'=>'Supplier','email'=>'supplier@example.com'])->assertCreated()->json('supplier.id');
  $this->postJson('/api/categories',['name'=>'Supplies','classification'=>'Non-Perishable'])->assertCreated();
  $this->product=$this->postJson('/api/products',['name'=>'Coke','category'=>'Supplies','barcode'=>'COKE','stock'=>0,'unitPrice'=>100,'price'=>150,'purchaseUnit'=>'Piece','stockUnit'=>'Piece','conversionFactor'=>1,'supplierId'=>$this->supplier])->assertCreated()->json('product.id');
 }
 private function order($qty) {
  $id=$this->actingAs($this->manager)->postJson('/api/purchase-requests',['category'=>'Supplies','lines'=>[['productId'=>$this->product,'qty'=>$qty,'supplierId'=>$this->supplier]]])->assertCreated()->json('id');
  $this->actingAs($this->admin)->patchJson('/api/purchase-requests/'.$id,['action'=>'approved','revision'=>0])->assertOk();
  $this->actingAs($this->manager);return $id;
 }
 private function entry($po,$delivered,$damaged=0,$version=0,$type='current') {
  return ['poId'=>$po,'version'=>$version,'productId'=>$this->product,'type'=>$type,'delivered'=>$delivered,'damaged'=>$damaged,'damageReason'=>$damaged?'Crushed packaging':null,'disposition'=>'return','unitCost'=>100,'lots'=>$delivered>$damaged?[['quantity'=>$delivered-$damaged,'supplierLot'=>'SUP-123']]:[]];
 }
 private function body($po,$entries) {return ['requestKey'=>(string)Str::uuid(),'primaryPo'=>$po,'supplierId'=>$this->supplier,'deliveryDate'=>now()->toDateString(),'entries'=>$entries];}
 public function test_purchasing_listing_batches_database_reads():void {
  $old=$this->order(50);
  $this->postJson('/api/stock-receipts',$this->body($old,[$this->entry($old,45,5)]))->assertCreated();
  $read=function(){
   DB::enableQueryLog();DB::flushQueryLog();
   $response=$this->getJson('/api/purchasing')->assertOk();
   $queries=count(DB::getQueryLog());DB::disableQueryLog();
   return [$response,$queries];
  };
  [$before,$count]=$read();
  for($i=0;$i<4;$i++)$this->order(10);
  [$after,$largerCount]=$read();
  $this->assertSame($count,$largerCount,'Listing queries must not grow with each PO or line.');
  $order=collect($after->json('orders'))->firstWhere('id',$old);
  $this->assertSame(['missing'=>5,'damaged'=>5,'unclassified'=>false,'total'=>10],$order['lines'][0]['obligation']);
  $this->assertEquals(1000,$order['receivingBalance']);
 }
 public function test_missing_and_damaged_are_resolved_under_original_po_in_one_delivery():void {
  $old=$this->order(50);
  $first=$this->body($old,[$this->entry($old,45,5)]);
  $id=$this->postJson('/api/stock-receipts',$first)->assertCreated()->assertJsonPath('status','Posted')->json('id');
  $this->assertMatchesRegularExpression('/^SR-\d{2}-\d{2}-\d{2}-\d{5,}$/',$id);
  $this->assertMatchesRegularExpression('/^BATCH-\d{2}-\d{2}-\d{2}-\d{5,}$/',DB::table('physical_inventory_lots')->where('stockReceipt',$id)->value('id'));
  $this->assertMatchesRegularExpression('/^RCV-\d{2}-\d{2}-\d{2}-\d{5,}$/',DB::table('receiving_records')->where('deliveryBatch',$id)->value('id'));
  $this->postJson('/api/stock-receipts',$first)->assertOk()->assertJsonPath('id',$id);
  $this->assertDatabaseHas('products',['id'=>$this->product,'stock'=>40]);
  $this->assertDatabaseHas('receiving_obligation_events',['stockReceipt'=>$id,'missingAfter'=>5,'damageAfter'=>5]);
  $new=$this->order(30);
  $next=$this->body($new,[$this->entry($new,30),$this->entry($old,5,0,1,'missing')]);
  $id=$this->postJson('/api/stock-receipts',$next)->assertCreated()->json('id');
  $this->assertDatabaseHas('products',['id'=>$this->product,'stock'=>75]);
  $child=DB::table('receiving_records')->where('deliveryBatch',$id)->where('poId',$old)->first();
  $snapshot=json_decode($child->inspection,true);
  $this->assertSame(0,$snapshot[0]['missing']);$this->assertSame(5,$snapshot[0]['damageDue']);
  $this->assertDatabaseHas('purchase_order_lines',['poId'=>$old,'deliveredQty'=>45,'damageOutstanding'=>5]);
  $this->assertEquals(1,DB::table('physical_inventory_lots')->where('stockReceipt',$id)->count());
  $this->assertEquals(2,DB::table('receiving_records')->where('deliveryBatch',$id)->count());
  $this->get('/stock-receipts/'.$id.'/report')->assertOk()->assertSee($old)->assertSee($new);
  $this->postJson('/api/stock-receipts',$this->body($old,[$this->entry($old,5,0,2,'damaged')]))->assertCreated();
  $this->assertDatabaseHas('purchase_orders',['id'=>$old,'status'=>'Fully Received']);
  $this->assertDatabaseHas('products',['id'=>$this->product,'stock'=>80]);
 }
 public function test_invalid_lot_or_damage_posts_nothing():void {
  $po=$this->order(10);$e=$this->entry($po,5);$e['lots'][0]['quantity']=4;
  $this->postJson('/api/stock-receipts',$this->body($po,[$e]))->assertUnprocessable();
  $e=$this->entry($po,5,6);
  $this->postJson('/api/stock-receipts',$this->body($po,[$e]))->assertUnprocessable();
  $this->assertDatabaseCount('stock_receipts',0);$this->assertDatabaseCount('receiving_records',0);
 }
 public function test_over_budget_receiving_is_rejected_without_approval_or_inventory():void {
  $po=$this->order(10);$e=$this->entry($po,10);$e['unitCost']=110;$body=$this->body($po,[$e]);
  $this->postJson('/api/stock-receipts',$body)->assertUnprocessable()->assertSee('remaining approved value');
  $this->assertDatabaseCount('stock_receipts',0);
  $this->assertDatabaseHas('products',['id'=>$this->product,'stock'=>0]);
 }
 public function test_partial_higher_cost_uses_remaining_value_across_receipts():void {
  $po=$this->order(10);$e=$this->entry($po,5);$e['unitCost']=110;$body=$this->body($po,[$e]);$body['notes']='Actual purchase cost';
  $id=$this->postJson('/api/stock-receipts',$body)->assertCreated()->json('id');
  $this->actingAs($this->admin)->patchJson('/api/stock-receipts/'.$id.'/review',['action'=>'Approved','note'=>'Within fixed value'])->assertOk();
  $this->actingAs($this->manager)->postJson('/api/stock-receipts/'.$id.'/post')->assertOk();
  $e=$this->entry($po,5,0,1);$this->postJson('/api/stock-receipts',$this->body($po,[$e]))->assertUnprocessable();
  $e['unitCost']=90;$body=$this->body($po,[$e]);$body['notes']='Within remaining value';
  $id=$this->postJson('/api/stock-receipts',$body)->assertCreated()->json('id');
  $this->actingAs($this->admin)->patchJson('/api/stock-receipts/'.$id.'/review',['action'=>'Approved','note'=>'Within remaining value'])->assertOk();
  $this->actingAs($this->manager)->postJson('/api/stock-receipts/'.$id.'/post')->assertOk();
  $this->assertDatabaseHas('inventory_batches',['productId'=>$this->product,'unitCost'=>110,'quantityReceived'=>5]);
  $this->assertDatabaseHas('inventory_batches',['productId'=>$this->product,'unitCost'=>90,'quantityReceived'=>5]);
 }
 public function test_missing_substitution_posts_only_replacement_and_cannot_raise_budget():void {
  $po=$this->order(10);
  $this->postJson('/api/stock-receipts',$this->body($po,[$this->entry($po,5)]))->assertCreated();
  $replacement=$this->postJson('/api/products',['name'=>'Sprite','category'=>'Supplies','barcode'=>'SPRITE','stock'=>0,'unitPrice'=>90,'price'=>140,'purchaseUnit'=>'Piece','stockUnit'=>'Piece','conversionFactor'=>1,'supplierId'=>$this->supplier])->assertCreated()->json('product.id');
  $e=$this->entry($po,5,0,1,'missing');$e['substituteProductId']=$replacement;$e['substitutionReason']='Supplier replacement';$e['unitCost']=90;
  $body=$this->body($po,[$e]);$body['notes']='Equal or lower value replacement';
  $id=$this->postJson('/api/stock-receipts',$body)->assertCreated()->json('id');
  $this->actingAs($this->admin)->patchJson('/api/stock-receipts/'.$id.'/review',['action'=>'Approved','note'=>'Replacement verified'])->assertOk();
  $this->actingAs($this->manager)->postJson('/api/stock-receipts/'.$id.'/post')->assertOk();
  $this->assertDatabaseHas('products',['id'=>$this->product,'stock'=>5]);
  $this->assertDatabaseHas('products',['id'=>$replacement,'stock'=>5]);
  $this->assertDatabaseHas('purchase_order_lines',['poId'=>$po,'productId'=>$this->product,'deliveredQty'=>10]);
  $this->postJson('/api/purchase-orders/'.$po.'/budget-requests',['requestKey'=>(string)Str::uuid(),'version'=>2,'amount'=>100,'reason'=>'Increase'])->assertUnprocessable();
 }
 public function test_all_damaged_delivery_records_obligation_without_inventory():void {
  $po=$this->order(10);
  $this->postJson('/api/stock-receipts',$this->body($po,[$this->entry($po,10,10)]))->assertCreated();
  $this->assertDatabaseHas('products',['id'=>$this->product,'stock'=>0]);
  $this->assertDatabaseCount('inventory_batches',0);
  $this->assertDatabaseHas('purchase_order_lines',['poId'=>$po,'damageOutstanding'=>10,'deliveredQty'=>0]);
 }
 public function test_stale_approval_and_expired_lots_cannot_post():void {
  $po=$this->order(10);$e=$this->entry($po,5);$e['unitCost']=101;
  $body=$this->body($po,[$e]);$body['notes']='Price changed';
  $id=$this->postJson('/api/stock-receipts',$body)->assertCreated()->json('id');
  $this->postJson('/api/stock-receipts',$this->body($po,[$this->entry($po,1)]))->assertCreated();
  $this->actingAs($this->admin)->patchJson('/api/stock-receipts/'.$id.'/review',['action'=>'Approved','note'=>'Checked'])->assertConflict();
  $this->actingAs($this->manager);$e=$this->entry($po,2,0,1);$e['lots'][0]['expiryDate']=now()->toDateString();
  $this->postJson('/api/stock-receipts',$this->body($po,[$e]))->assertUnprocessable();
  $this->assertDatabaseHas('products',['id'=>$this->product,'stock'=>1]);
 }
 public function test_multiple_lots_preserve_separate_expiration_and_purchase_costs():void {
  $po=$this->order(10);$e=$this->entry($po,10);
  $e['lots']=[['quantity'=>4,'supplierLot'=>'A','expiryDate'=>now()->addDays(10)->toDateString()],['quantity'=>6,'supplierLot'=>'B','expiryDate'=>now()->addDays(20)->toDateString()]];
  $this->postJson('/api/stock-receipts',$this->body($po,[$e]))->assertCreated();
  $this->assertDatabaseCount('physical_inventory_lots',2);
  $this->assertDatabaseHas('inventory_batches',['quantityReceived'=>4,'unitCost'=>100,'lotNumber'=>'A']);
  $this->assertDatabaseHas('inventory_batches',['quantityReceived'=>6,'unitCost'=>100,'lotNumber'=>'B']);
 }

}
