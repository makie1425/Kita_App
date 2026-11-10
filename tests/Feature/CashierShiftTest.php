<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
class CashierShiftTest extends TestCase {
 use RefreshDatabase;
 use \Tests\Concerns\UsesWorkspaceFixtures;
 private User $cashier; private User $manager;
 protected function setUp():void {parent::setUp();$this->manager=User::factory()->create(['role'=>'manager','approval_pin'=>Hash::make('1234')]);$this->cashier=User::factory()->create(['role'=>'cashier']);$this->assignWorkspace($this->manager,$this->cashier);$this->actingAs($this->cashier);$this->insertFixture('categories',['name'=>'General','status'=>'Active']);$this->insertFixture('products',['id'=>1,'name'=>'Item','category'=>'General','status'=>'Active','stock'=>3,'price'=>10]);}
 public function test_cash_sale_requires_open_shift_and_records_net_sale():void {
  $sale=['uuid'=>'SHIFT-SALE','items'=>[['productId'=>1,'qty'=>1]],'paymentMode'=>'Cash','tendered'=>20];
  $this->postJson('/api/transactions',$sale)->assertUnprocessable()->assertJsonValidationErrors('shift');
  $this->postJson('/api/payments/paymongo/checkout',['uuid'=>'WALLET-SHIFT','stockItems'=>[['productId'=>1,'qty'=>1]],'provider'=>'QR Ph'])->assertUnprocessable()->assertJsonValidationErrors('shift');
  $id=$this->postJson('/api/cashier-shifts',['openingCash'=>100])->assertCreated()->json('shift.id');
  $this->postJson('/api/cashier-shifts',['openingCash'=>100])->assertUnprocessable();
  $this->postJson('/api/transactions',$sale)->assertCreated();
  $this->getJson('/api/cashier-shifts/current')->assertOk()->assertJsonPath('expectedCash',110)->assertJsonCount(1,'entries');
  $this->assertDatabaseHas('cashier_shift_entries',['shift_id'=>$id,'type'=>'sale','amount'=>10]);
  $this->postJson('/api/cashier-shifts/close',['countedCash'=>109])->assertUnprocessable()->assertJsonValidationErrors('differenceReason');
  $this->postJson('/api/cashier-shifts/close',['countedCash'=>109,'differenceReason'=>'One peso short'])->assertOk()->assertJsonPath('shift.expected_cash',110)->assertJsonPath('shift.counted_cash',109);
  $this->postJson('/api/transactions',['uuid'=>'SHIFT-SALE-2','items'=>[['productId'=>1,'qty'=>1]],'paymentMode'=>'Cash','tendered'=>10])->assertUnprocessable()->assertJsonValidationErrors('shift');
  $this->get('/api/cashier-shifts/'.$id.'/report')->assertOk()->assertSee('One peso short');
 }
 public function test_manager_can_review_shift_and_other_cashiers_cannot():void {
  $id=$this->postJson('/api/cashier-shifts',['openingCash'=>75])->assertCreated()->json('shift.id');
  $other=User::factory()->create(['role'=>'cashier']);$this->assignMember($other);
  $this->actingAs($other)->getJson('/api/cashier-shifts/history')->assertOk()->assertJsonCount(0,'shifts');
  $this->get('/api/cashier-shifts/'.$id.'/report')->assertForbidden();
  $this->actingAs($this->manager)->getJson('/api/cashier-shifts/history')->assertOk()->assertJsonCount(1,'shifts');
  $this->get('/api/cashier-shifts/'.$id.'/report')->assertOk()->assertSee('75.00');
 }
 public function test_movement_requires_manager_approval_and_preserves_balance():void {
  $this->postJson('/api/cashier-shifts',['openingCash'=>100])->assertCreated();
  $this->postJson('/api/cashier-shifts/movement',['type'=>'removed','amount'=>10,'reason'=>'Bank deposit'])->assertUnprocessable();
  $this->postJson('/api/cashier-shifts/movement',['type'=>'removed','amount'=>10,'reason'=>'Bank deposit','manager_id'=>$this->manager->id,'manager_pin'=>'1234'])->assertOk()->assertJsonPath('expectedCash',90);
  $this->postJson('/api/cashier-shifts/movement',['type'=>'added','amount'=>25,'reason'=>'Change fund','manager_id'=>$this->manager->id,'manager_pin'=>'1234'])->assertOk()->assertJsonPath('expectedCash',115);
 }
}
