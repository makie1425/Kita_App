<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
class SupplierPersistenceTest extends TestCase {
 use RefreshDatabase;use \Tests\Concerns\UsesWorkspaceFixtures;
 public function test_supplier_and_links_persist_and_other_accounts_are_denied():void {
  $manager=User::factory()->create(['role'=>'manager']);$this->assignWorkspace($manager);$this->actingAs($manager);
  $this->insertFixture('categories',['name'=>'Food','status'=>'Active']);
  $this->insertFixture('products',['id'=>1,'name'=>'Rice','category'=>'Food','status'=>'Active','stock'=>0]);
  $id=$this->postJson('/api/suppliers',['name'=>'Grocery Supply','category'=>'Food','notes'=>'Morning deliveries'])->assertCreated()->json('supplier.id');
  $this->patchJson('/api/suppliers/'.$id,['name'=>'Grocery Supply','notes'=>'Updated instructions','products'=>[['productId'=>1,'costPrice'=>25,'preferred'=>true]]])->assertOk();
  $this->getJson('/api/kita-data')->assertOk()->assertJsonPath('SUPPLIERS.0.notes','Updated instructions')->assertJsonPath('SUPPLIERS.0.products.0.productId',1);
  $other=User::factory()->create(['role'=>'manager']);$this->actingAs($other)->patchJson('/api/suppliers/'.$id,['name'=>'Changed'])->assertForbidden();
  $this->actingAs($manager)->patchJson('/api/suppliers/'.$id,['name'=>'Grocery Supply','products'=>[['productId'=>999,'costPrice'=>25,'preferred'=>true]]])->assertUnprocessable();
  $this->assertDatabaseHas('supplier_products',['supplierId'=>$id,'productId'=>1]);
 }
}
