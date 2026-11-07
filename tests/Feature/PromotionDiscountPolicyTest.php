<?php
namespace Tests\Feature;
use App\Models\User;
use App\Services\FifoInventory;
use App\Services\ExpiryMonitoring;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
class PromotionDiscountPolicyTest extends TestCase {
 use RefreshDatabase;
 use \Tests\Concerns\UsesWorkspaceFixtures;
 private $manager;private $cashier;
 protected function setUp():void {
  parent::setUp();$this->travelTo(\Carbon\Carbon::parse('2026-11-07 12:00:00','Asia/Manila'));
  $this->manager=User::factory()->create(['role'=>'manager','approval_pin'=>Hash::make('1234')]);$this->cashier=User::factory()->create(['role'=>'cashier']);$this->assignWorkspace($this->manager,$this->cashier);$this->actingAs($this->manager);
  $this->insertFixture('categories',['name'=>'Food','status'=>'Active']);
  $this->insertFixture('products',['id'=>1,'name'=>'Hotdog Buns','category'=>'Food','status'=>'Active','stock'=>0,'price'=>83.75,'unitPrice'=>30,'unit'=>'Piece','stockUnit'=>'Piece']);
  FifoInventory::receive(1,50,30,'2026-11-07',batch:'BUNS',expiry:'2026-12-07');DB::table('products')->where('id',1)->update(['stock'=>50]);
  Http::preventStrayRequests();
 }
 private function promotion($type='Near-Expiry'):array {return ['name'=>'Buns offer','type'=>$type,'productIds'=>[1],'discountPct'=>20,'startDate'=>now()->toDateString(),'endDate'=>now()->addDays(40)->toDateString()];}
 private function sale($uuid,$type='senior',$qty=1):array {return ['uuid'=>$uuid,'items'=>[['productId'=>1,'qty'=>$qty]],'paymentMode'=>'Cash','tendered'=>500,'discountType'=>$type,'discount_manager_id'=>$this->manager->id,'discount_manager_pin'=>'1234'];}
 public function test_promotion_then_senior_and_pwd_totals_and_reports_use_saved_breakdowns():void {
  $this->postJson('/api/promotions',$this->promotion())->assertCreated();
  foreach(['senior','pwd'] as $type){
   $this->actingAs($this->cashier)->postJson('/api/transactions',$this->sale('TXN-'.$type,$type)+['promotionDiscount'=>0,'customerDiscount'=>0])->assertCreated()->assertJsonPath('transaction.total',60.3)->assertJsonPath('transaction.promotionDiscount',16.75)->assertJsonPath('transaction.customerDiscount',6.7)->assertJsonPath('transaction.discountAmount',23.45);
   $this->assertDatabaseHas('transaction_lines',['transaction_uuid'=>'TXN-'.$type,'promotionDiscount'=>16.75,'customerDiscount'=>6.7,'lineTotal'=>60.3]);
  }
  $this->actingAs($this->manager)->getJson('/reports/data?type=sales')->assertOk()->assertJsonPath('rows.0.promotionDiscount',16.75)->assertJsonPath('rows.0.customerDiscount',6.7);
  $this->get('/reports/data?type=sales&format=print')->assertOk()->assertSee('Promotion Discount')->assertSee('60.30');
  $this->getJson('/api/kita-data')->assertOk()->assertJsonPath('TRANSACTIONS.0.customerDiscount',6.7);
 }
 public function test_quantity_rounding_and_refund_use_discounted_line_amount():void {
  $this->postJson('/api/promotions',$this->promotion())->assertCreated();
  $this->actingAs($this->cashier)->postJson('/api/transactions',$this->sale('TXN-multiple','senior',3))->assertCreated()->assertJsonPath('transaction.total',180.9)->assertJsonPath('transaction.promotionDiscount',50.25)->assertJsonPath('transaction.customerDiscount',20.1);
  $this->patchJson('/api/transactions/TXN-multiple',['action'=>'refund','reason'=>'Returnable','refund_classification'=>'Returnable','items'=>[['productId'=>1,'qty'=>1]],'manager_id'=>$this->manager->id,'manager_pin'=>'1234'])->assertOk();
  $this->assertDatabaseHas('transactions',['uuid'=>'TXN-multiple','refundedAmount'=>60.3]);
 }
 public function test_centavo_rounding_is_applied_at_each_discount_stage():void {
  DB::table('products')->where('id',1)->update(['price'=>0.05,'unitPrice'=>0.01]);
  DB::table('inventory_batches')->where('productId',1)->update(['unitCost'=>0.01]);
  $promotion=$this->promotion();$promotion['discountPct']=10;$this->postJson('/api/promotions',$promotion)->assertCreated();
  $this->actingAs($this->cashier)->postJson('/api/transactions',$this->sale('TXN-rounding','pwd',3))->assertCreated()->assertJsonPath('transaction.total',0.13)->assertJsonPath('transaction.promotionDiscount',0.01)->assertJsonPath('transaction.customerDiscount',0.01);
 }
 public function test_promotion_cutoff_is_independent_of_the_thirty_day_alert_window():void {
  foreach([-1=>false,0=>false,1=>true,2=>true,7=>true,30=>true,31=>true,60=>true,90=>true] as $days=>$allowed){
   DB::table('inventory_batches')->update(['expiryDate'=>now()->addDays($days)->toDateString()]);
   $response=$this->postJson('/api/promotions',$this->promotion());
   if($allowed)$response->assertCreated();else $response->assertUnprocessable()->assertJsonValidationErrors('productIds');
   DB::table('promotion_products')->delete();DB::table('promotions')->delete();
  }
  DB::table('inventory_batches')->update(['expiryDate'=>now()->addDay()->toDateString()]);
  foreach(['Seasonal','Category-Wide'] as $type){
   $body=$this->promotion($type);if($type==='Category-Wide'){$body['category']='Food';unset($body['productIds']);}
   $this->postJson('/api/promotions',$body)->assertCreated();DB::table('promotion_products')->delete();DB::table('promotions')->delete();
  }
  $future=$this->promotion();$future['startDate']=now()->addDay()->toDateString();
  $this->postJson('/api/promotions',$future)->assertUnprocessable()->assertJsonValidationErrors('productIds');
 }
 public function test_promotions_apply_at_checkout_inside_and_outside_the_alert_window():void {
  foreach([1,2,7,30,31,60] as $days){
   $this->actingAs($this->manager);DB::table('inventory_batches')->update(['expiryDate'=>now()->addDays($days)->toDateString()]);
   $this->getJson('/api/inventory/availability?productIds[]=1')->assertOk()->assertJsonPath('products.0.nearExpiry',$days<=30);
   $this->postJson('/api/promotions',$this->promotion())->assertCreated();
   $this->actingAs($this->cashier)->postJson('/api/transactions',$this->sale('TXN-days-'.$days))->assertCreated()->assertJsonPath('transaction.total',60.3)->assertJsonPath('transaction.promotionDiscount',16.75)->assertJsonPath('transaction.customerDiscount',6.7);
   DB::table('promotion_products')->delete();DB::table('promotions')->delete();
  }
 }
 public function test_near_expiry_notifications_still_start_at_thirty_days():void {
  $this->assertSame(30,ExpiryMonitoring::days());
  foreach([60,31,30,7,1] as $days){
   DB::table('notifications')->delete();DB::table('inventory_batches')->update(['expiryDate'=>now()->addDays($days)->toDateString()]);
   $expected=$days<=30?1:0;
   $this->assertCount($expected,ExpiryMonitoring::batches());
   $this->assertSame($expected,ExpiryMonitoring::notify($this->manager));
   $this->assertSame(0,ExpiryMonitoring::notify($this->manager));
   if($expected)$this->assertDatabaseHas('notifications',['recipientId'=>$this->manager->id,'workspace_id'=>$this->fixtureWorkspace,'type'=>'Stock expiring soon']);
  }
 }
 public function test_near_expiry_promotion_still_requires_a_recorded_expiration_date():void {
  DB::table('inventory_batches')->update(['expiryDate'=>null]);
  $this->postJson('/api/promotions',$this->promotion())->assertUnprocessable()->assertJsonValidationErrors('productIds');
  $this->postJson('/api/promotions',$this->promotion('Seasonal'))->assertCreated();
 }
 public function test_existing_promotion_applies_until_expiration_and_expired_stock_is_blocked():void {
  DB::table('inventory_batches')->update(['expiryDate'=>now()->addDays(2)->toDateString()]);$this->postJson('/api/promotions',$this->promotion())->assertCreated();
  $this->travel(1)->days();
  $this->actingAs($this->cashier)->postJson('/api/transactions',$this->sale('TXN-before-expiry','none'))->assertCreated()->assertJsonPath('transaction.total',67)->assertJsonPath('transaction.promotionDiscount',16.75);
  $this->travel(1)->days();
  $this->postJson('/api/transactions',$this->sale('TXN-expired','none'))->assertUnprocessable();
  $this->assertDatabaseMissing('transactions',['uuid'=>'TXN-expired']);$this->assertDatabaseHas('products',['id'=>1,'stock'=>49]);
  $this->actingAs($this->manager)->getJson('/api/inventory/availability?productIds[]=1')->assertOk()->assertJsonPath('products.0.sellableStock',0)->assertJsonPath('products.0.promotionEligible',false);
 }
 public function test_qrph_uses_the_same_discounted_amount_as_cash():void {
  $this->postJson('/api/promotions',$this->promotion())->assertCreated();
  config(['services.paymongo.secret'=>'sk_test_example','services.paymongo.webhook_secret'=>'secret']);
  Http::fake(['*/checkout_sessions'=>Http::response(['data'=>['id'=>'cs_discount','attributes'=>['checkout_url'=>'https://checkout.paymongo.com/test']]],200)]);
  $body=$this->sale('TXN-qr','pwd');$body['stockItems']=$body['items'];$body['provider']='QR Ph';unset($body['items']);
  $this->actingAs($this->cashier)->postJson('/api/payments/paymongo/checkout',$body)->assertOk();
  Http::assertSent(fn($request)=>$request['data']['attributes']['line_items'][0]['amount']===6030);
  $this->assertDatabaseHas('transactions',['uuid'=>'TXN-qr','total'=>60.3,'promotionDiscount'=>16.75,'customerDiscount'=>6.7]);
 }
 public function test_earliest_available_batch_controls_promotion_eligibility():void {
  FifoInventory::receive(1,2,30,'2026-11-07',batch:'URGENT',expiry:now()->addDay()->toDateString());DB::table('products')->where('id',1)->increment('stock',2);
  $this->getJson('/api/inventory/availability?productIds[]=1')->assertOk()->assertJsonPath('products.0.promotionExpiry',now()->addDay()->toDateString())->assertJsonPath('products.0.promotionEligible',true)->assertJsonPath('products.0.nearExpiry',true);
  $this->postJson('/api/promotions',$this->promotion())->assertCreated();
 }
 public function test_historical_totals_are_not_recalculated_by_migration():void {
  $this->actingAs($this->cashier)->postJson('/api/transactions',$this->sale('TXN-history','none'))->assertCreated();
  DB::table('transactions')->where('uuid','TXN-history')->update(['promotionDiscount'=>null,'customerDiscount'=>null,'overrideDiscount'=>null]);
  DB::table('transaction_lines')->where('transaction_uuid','TXN-history')->update(['promotionDiscount'=>null,'customerDiscount'=>null,'overrideDiscount'=>null]);
  $this->actingAs($this->manager)->getJson('/reports/data?type=sales')->assertOk()->assertJsonPath('rows.0.promotionDiscount',null)->assertJsonPath('rows.0.amount',83.75);
 }
}
