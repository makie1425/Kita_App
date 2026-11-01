<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class WorkspaceBusinessReadTest extends TestCase {
 use RefreshDatabase;
 private function actor($label,$role='manager'){
  $org=DB::table('organizations')->insertGetId(['name'=>$label]);
  $scope=DB::table('workspaces')->insertGetId(['organization_id'=>$org,'name'=>$label]);
  $user=User::factory()->create(['role'=>$role]);$user->forceFill(['workspace_id'=>$scope])->save();
  DB::table('workspace_memberships')->insert(['workspace_id'=>$scope,'user_id'=>$user->id,'user_role'=>$role,'active'=>true,'can_manage_accounts'=>$role==='manager']);
  DB::table('categories')->insert(['name'=>$label,'status'=>'Active','workspace_id'=>$scope]);
  DB::table('products')->insert(['id'=>$scope,'name'=>$label.' product','category'=>$label,'status'=>'Active','stock'=>0,'price'=>10,'workspace_id'=>$scope]);
  return $user;
 }
 public function test_bootstrap_and_inventory_are_isolated_and_filter_ids_cannot_bypass():void{
  config(['workspaces.enabled'=>true]);$a=$this->actor('Alpha');$b=$this->actor('Beta');
  $this->actingAs($a)->getJson('/api/kita-data')->assertOk()->assertJsonCount(1,'PRODUCTS')->assertJsonPath('PRODUCTS.0.name','Alpha product')->assertDontSee('Beta product');
  $this->getJson('/api/inventory-history')->assertOk()->assertJsonCount(1,'products')->assertJsonPath('products.0.name','Alpha product');
  $this->getJson('/api/inventory-history?productId='.$b->workspace_id)->assertOk()->assertJsonCount(0,'products');
  $this->getJson('/api/inventory/availability?productId='.$b->workspace_id.'&quantity=1')->assertNotFound();
  $this->getJson('/api/inventory/availability')->assertOk()->assertJsonCount(1,'products');
 }
 public function test_purchasing_lists_hide_other_workspace_requests():void{
  config(['workspaces.enabled'=>true]);$a=$this->actor('Alpha');$b=$this->actor('Beta');
  foreach([$a,$b] as $user)DB::table('item_requests')->insert(['id'=>'REQ-'.$user->id,'category'=>'Category','status'=>'Pending Approval','dateRequested'=>now()->toDateString(),'requestedBy'=>$user->name,'workspace_id'=>$user->workspace_id]);
  $this->actingAs($a)->getJson('/api/purchasing')->assertOk()->assertJsonCount(1,'requests')->assertJsonPath('requests.0.id','REQ-'.$a->id)->assertJsonCount(0,'orders');
 }
 public function test_new_unassigned_account_and_revoked_member_see_no_legacy_or_other_records():void{
  config(['workspaces.enabled'=>true]);$a=$this->actor('Alpha');
  DB::table('products')->insert(['id'=>99,'name'=>'Legacy private product','category'=>'Alpha','status'=>'Active','stock'=>0,'price'=>10]);
  $new=User::factory()->create(['role'=>'manager']);
  $this->actingAs($new)->getJson('/api/kita-data')->assertOk()->assertJsonCount(0,'PRODUCTS')->assertJsonCount(0,'CATEGORIES');
  DB::table('workspace_memberships')->where('user_id',$a->id)->update(['active'=>false]);
  $this->actingAs($a)->getJson('/api/inventory-history')->assertOk()->assertJsonCount(0,'products');
 }
 public function test_catalog_writes_assign_scope_and_reject_foreign_targets_and_categories():void{
  config(['workspaces.enabled'=>true]);$a=$this->actor('Alpha');$b=$this->actor('Beta');
  $foreign=DB::table('brands')->insertGetId(['name'=>'Shared brand','workspace_id'=>$b->workspace_id]);
  $this->actingAs($a)->postJson('/api/product-master/brands',['name'=>'Shared brand','status'=>'Active','workspace_id'=>$b->workspace_id])->assertOk()->assertJsonPath('record.workspace_id',$a->workspace_id);
  $this->patchJson('/api/product-master/brands/'.$foreign,['name'=>'Changed','status'=>'Active'])->assertNotFound();
  $this->assertDatabaseHas('brands',['id'=>$foreign,'name'=>'Shared brand','workspace_id'=>$b->workspace_id]);
  $this->postJson('/api/product-master/subcategories',['name'=>'Private','status'=>'Active','category'=>'Beta'])->assertUnprocessable();
  $this->postJson('/api/product-master/subcategories',['name'=>'Allowed','status'=>'Active','category'=>'Alpha'])->assertOk()->assertJsonPath('record.workspace_id',$a->workspace_id);
  $this->assertDatabaseHas('audit_logs',['action'=>'Saved subcategories','workspace_id'=>$a->workspace_id]);
  DB::table('workspace_memberships')->where('user_id',$a->id)->update(['active'=>false]);
  $this->postJson('/api/product-master/brands',['name'=>'Denied','status'=>'Active'])->assertForbidden();
  $this->assertDatabaseMissing('brands',['name'=>'Denied']);
 }
 public function test_foreign_receipt_and_purchase_order_documents_are_not_accessible():void{
  config(['workspaces.enabled'=>true]);$a=$this->actor('Alpha');$b=$this->actor('Beta');
  DB::table('purchase_orders')->insert(['id'=>'PO-PRIVATE','workspace_id'=>$b->workspace_id]);
  DB::table('receiving_records')->insert(['id'=>'RCV-PRIVATE','poId'=>'PO-PRIVATE','workspace_id'=>$b->workspace_id]);
  $this->actingAs($a)->get('/purchase-orders/PO-PRIVATE/order')->assertNotFound();
  $this->get('/purchase-orders/PO-PRIVATE/report')->assertNotFound();
  $this->get('/receipts/RCV-PRIVATE/report')->assertNotFound();
 }
}
