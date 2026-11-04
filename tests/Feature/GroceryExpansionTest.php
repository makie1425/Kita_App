<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Models\User;
class GroceryExpansionTest extends TestCase {
 use RefreshDatabase;use \Tests\Concerns\UsesWorkspaceFixtures;
 public function test_expansion_is_complete_scoped_and_repeat_safe():void {
  $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-04 12:00:00','Asia/Manila'));
  $m=User::factory()->create(['role'=>'manager']);$a=User::factory()->create(['role'=>'admin']);$this->assignWorkspace($m,$a);
  $args=['admin'=>$a->email,'manager'=>$m->email,'--apply'=>true];
  DB::table('grocery_imports')->insert(['workspace_id'=>$this->fixtureWorkspace,'manager_id'=>$m->id,'products'=>'[]','completed_steps'=>150,'completed'=>true,'created_at'=>now(),'updated_at'=>now()]);
  $interrupt=true;
  DB::listen(function($query)use(&$interrupt){if($interrupt && str_starts_with(strtolower($query->sql),'insert into "transactions"') && in_array('G'.$this->fixtureWorkspace.'-V2-SALE-1',$query->bindings,true)){$interrupt=false;throw new \RuntimeException('Interrupted checkpoint');}});
  $this->assertSame(1,\Illuminate\Support\Facades\Artisan::call('presentation:expand-grocery',$args));
  $this->assertFalse($interrupt);$this->assertDatabaseCount('transactions',1);
  $exit=\Illuminate\Support\Facades\Artisan::call('presentation:expand-grocery',$args);$this->assertSame(0,$exit,\Illuminate\Support\Facades\Artisan::output());
  $this->assertDatabaseCount('products',70);$this->assertDatabaseCount('suppliers',5);$this->assertDatabaseCount('subcategories',30);$this->assertDatabaseCount('transactions',350);$this->assertDatabaseCount('item_requests',49);
  $this->assertGreaterThanOrEqual(150,DB::table('inventory_batches')->count());
  $this->assertGreaterThanOrEqual(20,DB::table('inventory_batches')->where('expiryDate','>',now()->toDateString())->where('expiryDate','<=',now()->addDays(7)->toDateString())->where('quantityRemaining','>',0)->count());
  $this->assertGreaterThanOrEqual(10,DB::table('inventory_batches')->where('expiryDate','<=',now()->toDateString())->where('quantityRemaining','>',0)->count());
  $this->assertDatabaseHas('transactions',['date'=>now()->toDateString()]);
  foreach(['Pending Approval','Declined','Cancelled'] as $status)$this->assertDatabaseHas('item_requests',compact('status'));
  $this->assertGreaterThan(0,DB::table('item_requests')->where('status','Pending Approval')->where('revision','>',0)->count());
  $this->assertDatabaseHas('receiving_obligation_events',['type'=>'damaged','accepted'=>5]);
  foreach(DB::table('products')->get() as $p)$this->assertEquals($p->stock,DB::table('inventory_batches')->where('productId',$p->id)->sum('quantityRemaining'));
  $before=DB::table('inventory_batches')->count();$this->artisan('presentation:expand-grocery',$args)->assertSuccessful();$this->assertDatabaseCount('transactions',350);$this->assertDatabaseCount('inventory_batches',$before);
  $other=User::factory()->create(['role'=>'manager']);$this->actingAs($other)->getJson('/api/inventory/availability')->assertOk()->assertJsonCount(0,'products');
 }
}
